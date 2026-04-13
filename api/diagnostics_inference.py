#!/usr/bin/env python3
import argparse
import json
import math
import os
import sys
from typing import Dict, List

import numpy as np


def reconcile_predictions(preds_array: np.ndarray) -> np.ndarray:
    preds = np.clip(np.asarray(preds_array, dtype=float), a_min=0.0, a_max=None)
    tol, sens, res, mig = preds[0], preds[1], preds[2], preds[3]

    richness_a = tol + sens
    richness_b = res + mig
    consensus = (richness_a + richness_b) / 2.0
    eps = 1e-9

    adj_tol = consensus * (tol / (richness_a + eps))
    adj_sens = consensus * (sens / (richness_a + eps))
    adj_res = consensus * (res / (richness_b + eps))
    adj_mig = consensus * (mig / (richness_b + eps))
    return np.array([adj_tol, adj_sens, adj_res, adj_mig], dtype=float)


def r2_score_safe(y_true: np.ndarray, y_pred: np.ndarray) -> float:
    y_true = np.asarray(y_true, dtype=float)
    y_pred = np.asarray(y_pred, dtype=float)
    if y_true.size == 0:
        return 0.0
    denom = float(np.sum((y_true - np.mean(y_true)) ** 2))
    if denom <= 1e-12:
        return 0.0
    num = float(np.sum((y_true - y_pred) ** 2))
    return 1.0 - (num / denom)


def feature_group_map() -> Dict[str, str]:
    groups = {
        "ndvi": "NDVI",
        "ndvi_lag1": "NDVI",
        "lst_day": "Temperature",
        "viirs_avg_rad_log": "Artificial Light",
        "viirs_log_lag1": "Artificial Light",
        "monthly_precip_mm_log": "Precipitation",
        "month_sin": "Seasonality",
        "month_cos": "Seasonality",
        "lc_dummy_1": "Land Cover",
        "lc_dummy_2": "Land Cover",
        "lc_dummy_3": "Land Cover",
        "lc_dummy_4": "Land Cover",
        "lc_dummy_5": "Land Cover",
        "tolerant_lag1": "Historical Species",
        "sensitive_lag1": "Historical Species",
        "resident_lag1": "Historical Species",
        "migrant_lag1": "Historical Species",
    }
    return groups


def ordered_group_labels() -> List[str]:
    return [
        "Artificial Light",
        "NDVI",
        "Temperature",
        "Precipitation",
        "Seasonality",
        "Land Cover",
        "Historical Species",
    ]


def aggregate_importance_by_group(booster, fmap: Dict[str, str]) -> Dict[str, float]:
    scores = booster.get_score(importance_type="gain")
    groups = {k: 0.0 for k in ordered_group_labels()}

    for feat, val in scores.items():
        group = fmap.get(feat)
        if group is None and feat.startswith("f"):
            try:
                idx = int(feat[1:])
            except ValueError:
                idx = -1
            feature_order = [
                "ndvi", "lst_day", "viirs_avg_rad_log", "monthly_precip_mm_log",
                "month_sin", "month_cos", "ndvi_lag1", "viirs_log_lag1",
                "lc_dummy_1", "lc_dummy_2", "lc_dummy_3", "lc_dummy_4", "lc_dummy_5",
                "tolerant_lag1", "sensitive_lag1", "resident_lag1", "migrant_lag1",
            ]
            if 0 <= idx < len(feature_order):
                group = fmap.get(feature_order[idx])
        if group in groups:
            groups[group] += float(val)

    total = sum(groups.values())
    if total > 0:
        groups = {k: v / total for k, v in groups.items()}
    return groups


def build_xgb_features(cur, prev, month: int = 6) -> np.ndarray:
    month_angle = 2.0 * math.pi * ((month - 1) / 12.0)
    month_sin = math.sin(month_angle)
    month_cos = math.cos(month_angle)

    return np.array([
        float(cur["ndvi"]),
        float(cur["lst_day"]),
        float(np.log1p(max(0.0, float(cur["viirs_avg_rad"])))),
        float(np.log1p(max(0.0, float(cur["monthly_precip_mm"])))),
        float(month_sin),
        float(month_cos),
        float(prev["ndvi"]),
        float(np.log1p(max(0.0, float(prev["viirs_avg_rad"])))),
        1.0, 0.0, 0.0, 0.0, 0.0,
        float(np.log1p(max(0.0, float(prev["tolerant"])))),
        float(np.log1p(max(0.0, float(prev["sensitive"])))),
        float(np.log1p(max(0.0, float(prev["resident"])))),
        float(np.log1p(max(0.0, float(prev["migrant"])))),
    ], dtype=float)


def build_lstm_tensor(history_rows, seq_len: int, channels: int, height: int = 1, width: int = 1) -> np.ndarray:
    # ConvLSTM expects (batch, timesteps, height, width, channels).
    tensor = np.zeros((1, seq_len, height, width, channels), dtype=np.float32)
    use_rows = history_rows[-seq_len:]
    offset = seq_len - len(use_rows)

    for i, row in enumerate(use_rows):
        t = offset + i
        tensor[0, t, :, :, 0] = float(row["ndvi"])
        if channels > 1:
            tensor[0, t, :, :, 1] = float(row["lst_day"])
        if channels > 2:
            tensor[0, t, :, :, 2] = float(np.log1p(max(0.0, float(row["viirs_avg_rad"]))))
        if channels > 3:
            tensor[0, t, :, :, 3] = float(np.log1p(max(0.0, float(row["monthly_precip_mm"]))))
        if channels > 4:
            tensor[0, t, :, :, 4] = float(np.log1p(max(0.0, float(row["tolerant"]))))
        if channels > 5:
            tensor[0, t, :, :, 5] = float(np.log1p(max(0.0, float(row["sensitive"]))))
        if channels > 6:
            tensor[0, t, :, :, 6] = float(np.log1p(max(0.0, float(row["resident"]))))
        if channels > 7:
            tensor[0, t, :, :, 7] = float(np.log1p(max(0.0, float(row["migrant"]))))

    return tensor


def as_filter_series(years, actual4, pred4):
    out = {
        "all": {"years": [], "actual": [], "predicted": []},
        "light_sensitive": {"years": [], "actual": [], "predicted": []},
        "light_tolerant": {"years": [], "actual": [], "predicted": []},
        "migratory": {"years": [], "actual": [], "predicted": []},
        "resident": {"years": [], "actual": [], "predicted": []},
    }

    for i, y in enumerate(years):
        a_tol, a_sens, a_res, a_mig = actual4[i]
        p_tol, p_sens, p_res, p_mig = pred4[i]

        a_total = ((a_tol + a_sens) + (a_res + a_mig)) / 2.0
        p_total = ((p_tol + p_sens) + (p_res + p_mig)) / 2.0

        out["all"]["years"].append(int(y))
        out["all"]["actual"].append(round(float(a_total), 4))
        out["all"]["predicted"].append(round(float(p_total), 4))

        out["light_sensitive"]["years"].append(int(y))
        out["light_sensitive"]["actual"].append(round(float(a_sens), 4))
        out["light_sensitive"]["predicted"].append(round(float(p_sens), 4))

        out["light_tolerant"]["years"].append(int(y))
        out["light_tolerant"]["actual"].append(round(float(a_tol), 4))
        out["light_tolerant"]["predicted"].append(round(float(p_tol), 4))

        out["migratory"]["years"].append(int(y))
        out["migratory"]["actual"].append(round(float(a_mig), 4))
        out["migratory"]["predicted"].append(round(float(p_mig), 4))

        out["resident"]["years"].append(int(y))
        out["resident"]["actual"].append(round(float(a_res), 4))
        out["resident"]["predicted"].append(round(float(p_res), 4))

    return out


def compute_metrics(actual4, pred4):
    names = ["tolerant", "sensitive", "resident", "migrant"]
    by_class = {}
    rmses = []
    maes = []
    r2s = []

    for i, name in enumerate(names):
        yt = np.array([row[i] for row in actual4], dtype=float)
        yp = np.array([row[i] for row in pred4], dtype=float)

        rmse = float(np.sqrt(np.mean((yt - yp) ** 2))) if yt.size else 0.0
        mae = float(np.mean(np.abs(yt - yp))) if yt.size else 0.0
        r2 = float(r2_score_safe(yt, yp))

        by_class[name] = {
            "rmse": round(rmse, 6),
            "mae": round(mae, 6),
            "r2": round(r2, 6),
        }
        rmses.append(rmse)
        maes.append(mae)
        r2s.append(r2)

    ensemble = {
        "rmse": round(float(np.mean(rmses)) if rmses else 0.0, 6),
        "mae": round(float(np.mean(maes)) if maes else 0.0, 6),
        "r2": round(float(np.mean(r2s)) if r2s else 0.0, 6),
    }

    return {
        "ensemble_average": ensemble,
        "by_class": by_class,
    }


def load_training_metrics(model_dir: str) -> Dict:
    candidates = [
        os.path.join(model_dir, "model_metrics.json"),
        os.path.join(model_dir, "training_metrics.json"),
        os.path.join(os.path.dirname(model_dir), "model_metrics.json"),
        os.path.join(os.path.dirname(model_dir), "training_metrics.json"),
        os.path.join(os.path.dirname(os.path.dirname(model_dir)), "model_metrics.json"),
        os.path.join(os.path.dirname(os.path.dirname(model_dir)), "training_metrics.json"),
    ]

    for path in candidates:
        if not path or not os.path.isfile(path):
            continue
        try:
            with open(path, "r", encoding="utf-8") as f:
                raw = json.load(f)
            if not isinstance(raw, dict):
                continue

            metrics = raw.get("ensembleMetrics") or raw.get("metrics") or raw
            if not isinstance(metrics, dict):
                continue

            ensemble = metrics.get("ensemble_average") or metrics.get("ensembleAverage") or {}
            if not isinstance(ensemble, dict):
                ensemble = {}

            by_class = metrics.get("by_class") or metrics.get("byClass") or {}
            if not isinstance(by_class, dict):
                by_class = {}

            return {
                "ensemble_average": {
                    "rmse": round(float(ensemble.get("rmse", 0.0)), 6),
                    "mae": round(float(ensemble.get("mae", 0.0)), 6),
                    "r2": round(float(ensemble.get("r2", 0.0)), 6),
                },
                "by_class": by_class,
                "_metrics_source": path,
            }
        except Exception:
            continue

    return {}


def compute_diagnostics(payload):
    rows = payload.get("rows", [])
    model_dir = payload.get("model_dir", "")
    training_metrics = load_training_metrics(model_dir)

    rows = sorted(rows, key=lambda r: int(r.get("year", 0)))
    if not rows:
        metrics_payload = training_metrics or {"ensemble_average": {"rmse": 0.0, "mae": 0.0, "r2": 0.0}, "by_class": {}}
        return {
            "success": True,
            "xgboostFeatureImportance": {k: {"labels": ordered_group_labels(), "values": [0, 0, 0, 0, 0, 0, 0]} for k in ["all", "light_sensitive", "light_tolerant", "migratory", "resident"]},
            "convlstmPredictions": {k: {"years": [], "actual": [], "predicted": []} for k in ["all", "light_sensitive", "light_tolerant", "migratory", "resident"]},
            "ensembleMetrics": metrics_payload,
            "metricsSource": metrics_payload.get("_metrics_source", "training_metrics_file" if training_metrics else "computed_zero_fallback"),
        }

    import xgboost as xgb
    import joblib
    import tensorflow as tf

    fmap = feature_group_map()

    model_paths = {
        "tolerant": os.path.join(model_dir, "xgb_tolerant.json"),
        "sensitive": os.path.join(model_dir, "xgb_sensitive.json"),
        "resident": os.path.join(model_dir, "xgb_resident.json"),
        "migrant": os.path.join(model_dir, "xgb_migrant.json"),
        "clf": os.path.join(model_dir, "convlstm_classifier.keras"),
        "reg": os.path.join(model_dir, "convlstm_regressor.keras"),
        "meta": os.path.join(model_dir, "meta_learner.joblib"),
    }

    xgb_models = {
        "tolerant": xgb.Booster(model_file=model_paths["tolerant"]),
        "sensitive": xgb.Booster(model_file=model_paths["sensitive"]),
        "resident": xgb.Booster(model_file=model_paths["resident"]),
        "migrant": xgb.Booster(model_file=model_paths["migrant"]),
    }
    lstm_clf = tf.keras.models.load_model(model_paths["clf"], compile=False)
    lstm_reg = tf.keras.models.load_model(model_paths["reg"], compile=False)
    meta_model = joblib.load(model_paths["meta"])

    import_by_target = {
        "tolerant": aggregate_importance_by_group(xgb_models["tolerant"], fmap),
        "sensitive": aggregate_importance_by_group(xgb_models["sensitive"], fmap),
        "resident": aggregate_importance_by_group(xgb_models["resident"], fmap),
        "migrant": aggregate_importance_by_group(xgb_models["migrant"], fmap),
    }

    group_labels = ordered_group_labels()
    all_avg = {}
    for g in group_labels:
        all_avg[g] = float(np.mean([
            import_by_target["tolerant"].get(g, 0.0),
            import_by_target["sensitive"].get(g, 0.0),
            import_by_target["resident"].get(g, 0.0),
            import_by_target["migrant"].get(g, 0.0),
        ]))

    def pack(groups):
        return {
            "labels": group_labels,
            "values": [round(float(groups.get(g, 0.0)), 6) for g in group_labels],
        }

    xgb_feature_importance = {
        "all": pack(all_avg),
        "light_sensitive": pack(import_by_target["sensitive"]),
        "light_tolerant": pack(import_by_target["tolerant"]),
        "migratory": pack(import_by_target["migrant"]),
        "resident": pack(import_by_target["resident"]),
    }

    years = []
    actual4 = []
    pred4 = []

    input_shape = lstm_reg.input_shape
    seq_len = int(input_shape[1] or 6)
    height = int(input_shape[2] or 1)
    width = int(input_shape[3] or 1)
    channels = int(input_shape[4] or input_shape[-1] or 3)

    history = []
    for i, row in enumerate(rows):
        cur = {
            "year": int(row.get("year", 0)),
            "ndvi": float(row.get("ndvi", 0.0) or 0.0),
            "lst_day": float(row.get("lst_day", 0.0) or 0.0),
            "viirs_avg_rad": float(row.get("viirs_avg_rad", 0.0) or 0.0),
            "monthly_precip_mm": float(row.get("monthly_precip_mm", 0.0) or 0.0),
            "tolerant": float(row.get("tolerant", 0.0) or 0.0),
            "sensitive": float(row.get("sensitive", 0.0) or 0.0),
            "resident": float(row.get("resident", 0.0) or 0.0),
            "migrant": float(row.get("migrant", 0.0) or 0.0),
        }
        prev = history[-1] if history else cur

        xgb_feat = build_xgb_features(cur, prev)
        dmatrix = xgb.DMatrix(xgb_feat.reshape(1, -1))

        xgb_raw = [
            float(np.expm1(xgb_models["tolerant"].predict(dmatrix)[0])),
            float(np.expm1(xgb_models["sensitive"].predict(dmatrix)[0])),
            float(np.expm1(xgb_models["resident"].predict(dmatrix)[0])),
            float(np.expm1(xgb_models["migrant"].predict(dmatrix)[0])),
        ]
        xgb_rec = reconcile_predictions(np.array(xgb_raw, dtype=float))

        history_for_lstm = history + [cur]
        lstm_tensor = build_lstm_tensor(
            history_for_lstm,
            seq_len=seq_len,
            channels=channels,
            height=height,
            width=width,
        )

        try:
            clf_prob = float(lstm_clf.predict(lstm_tensor, verbose=0).reshape(-1)[0])
            reg_out = lstm_reg.predict(lstm_tensor, verbose=0).reshape(-1)
            reg_out = reg_out[:4] if reg_out.size >= 4 else np.pad(reg_out, (0, max(0, 4 - reg_out.size)))
            lstm_raw = np.zeros(4, dtype=float) if clf_prob < 0.5 else np.expm1(reg_out)
        except Exception:
            # Keep diagnostics running even when ConvLSTM input compatibility differs.
            lstm_raw = np.zeros(4, dtype=float)
        lstm_rec = reconcile_predictions(lstm_raw)

        meta_input = np.array([[
            xgb_rec[0], xgb_rec[1], xgb_rec[2], xgb_rec[3],
            lstm_rec[0], lstm_rec[1], lstm_rec[2], lstm_rec[3],
            float(cur["ndvi"]),
            float(cur["viirs_avg_rad"]),
        ]], dtype=float)

        meta_pred = np.asarray(meta_model.predict(meta_input), dtype=float).reshape(-1)
        if meta_pred.size < 4:
            meta_pred = np.pad(meta_pred, (0, 4 - meta_pred.size))
        final_pred = reconcile_predictions(meta_pred[:4])

        years.append(cur["year"])
        actual4.append([cur["tolerant"], cur["sensitive"], cur["resident"], cur["migrant"]])
        pred4.append(final_pred.tolist())

        history.append(cur)

    convlstm_predictions = as_filter_series(years, actual4, pred4)
    ensemble_metrics = training_metrics or compute_metrics(actual4, pred4)

    return {
        "success": True,
        "xgboostFeatureImportance": xgb_feature_importance,
        "convlstmPredictions": convlstm_predictions,
        "ensembleMetrics": ensemble_metrics,
        "metricsSource": ensemble_metrics.get("_metrics_source", "training_metrics_file" if training_metrics else "computed_live"),
    }


def main():
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--input-file", dest="input_file", default="")
    parser.add_argument("--output-file", dest="output_file", default="")
    args, _ = parser.parse_known_args()

    if args.input_file:
        with open(args.input_file, "r", encoding="utf-8") as f:
            payload = json.load(f)
    else:
        payload = json.load(sys.stdin)

    result = compute_diagnostics(payload)
    if args.output_file:
        with open(args.output_file, "w", encoding="utf-8") as f:
            json.dump(result, f)
    else:
        print(json.dumps(result))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        error_payload = {"success": False, "error": str(exc)}
        print(json.dumps(error_payload))
        sys.exit(1)
