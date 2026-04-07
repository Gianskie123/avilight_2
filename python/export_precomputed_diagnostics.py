import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List

import numpy as np


def _to_float_list(values: List[float], ndigits: int = 6) -> List[float]:
    return [round(float(v), ndigits) for v in values]


def _metrics(y_true: np.ndarray, y_pred: np.ndarray) -> Dict[str, float]:
    y_true = np.asarray(y_true, dtype=float)
    y_pred = np.asarray(y_pred, dtype=float)
    if y_true.size == 0:
        return {"rmse": 0.0, "mae": 0.0, "r2": 0.0}

    rmse = float(np.sqrt(np.mean((y_true - y_pred) ** 2)))
    mae = float(np.mean(np.abs(y_true - y_pred)))

    denom = float(np.sum((y_true - np.mean(y_true)) ** 2))
    r2 = 0.0 if denom <= 1e-12 else float(1.0 - (np.sum((y_true - y_pred) ** 2) / denom))

    return {"rmse": round(rmse, 6), "mae": round(mae, 6), "r2": round(r2, 6)}


def build_diagnostics_payload(
    years: List[int],
    actual_by_class: Dict[str, List[float]],
    predicted_by_class: Dict[str, List[float]],
    shap_importance_by_filter: Dict[str, Dict[str, float]],
) -> Dict:
    """
    Build diagnostics JSON artifact to be consumed by api/get_report_data.php.

    Required class keys: tolerant, sensitive, resident, migrant
    Required filter keys for SHAP: all, light_sensitive, light_tolerant, migratory, resident
    SHAP group keys expected per filter:
      Artificial Light, NDVI, Temperature, Precipitation, Seasonality, Land Cover, Historical Species
    """

    class_keys = ["tolerant", "sensitive", "resident", "migrant"]
    filter_keys = ["all", "light_sensitive", "light_tolerant", "migratory", "resident"]
    group_labels = [
        "Artificial Light",
        "NDVI",
        "Temperature",
        "Precipitation",
        "Seasonality",
        "Land Cover",
        "Historical Species",
    ]

    # ConvLSTM actual vs predicted series by filter
    conv = {
        "all": {"years": list(map(int, years)), "actual": [], "predicted": []},
        "light_sensitive": {"years": list(map(int, years)), "actual": [], "predicted": []},
        "light_tolerant": {"years": list(map(int, years)), "actual": [], "predicted": []},
        "migratory": {"years": list(map(int, years)), "actual": [], "predicted": []},
        "resident": {"years": list(map(int, years)), "actual": [], "predicted": []},
    }

    tol_a = np.asarray(actual_by_class["tolerant"], dtype=float)
    sen_a = np.asarray(actual_by_class["sensitive"], dtype=float)
    res_a = np.asarray(actual_by_class["resident"], dtype=float)
    mig_a = np.asarray(actual_by_class["migrant"], dtype=float)

    tol_p = np.asarray(predicted_by_class["tolerant"], dtype=float)
    sen_p = np.asarray(predicted_by_class["sensitive"], dtype=float)
    res_p = np.asarray(predicted_by_class["resident"], dtype=float)
    mig_p = np.asarray(predicted_by_class["migrant"], dtype=float)

    actual_richness = []
    predicted_richness = []
    for i in range(len(years)):
        actual_resident_migrant = float(res_a[i] + mig_a[i])
        actual_tolerant_sensitive = float(tol_a[i] + sen_a[i])
        predicted_resident_migrant = float(res_p[i] + mig_p[i])
        predicted_tolerant_sensitive = float(tol_p[i] + sen_p[i])

        if actual_resident_migrant > 0:
            actual_richness.append(actual_resident_migrant)
        else:
            actual_richness.append(actual_tolerant_sensitive)

        if predicted_resident_migrant > 0:
            predicted_richness.append(predicted_resident_migrant)
        else:
            predicted_richness.append(predicted_tolerant_sensitive)

    conv["all"]["actual"] = _to_float_list(actual_richness, 4)
    conv["all"]["predicted"] = _to_float_list(predicted_richness, 4)
    conv["light_sensitive"]["actual"] = _to_float_list(sen_a.tolist(), 4)
    conv["light_sensitive"]["predicted"] = _to_float_list(sen_p.tolist(), 4)
    conv["light_tolerant"]["actual"] = _to_float_list(tol_a.tolist(), 4)
    conv["light_tolerant"]["predicted"] = _to_float_list(tol_p.tolist(), 4)
    conv["migratory"]["actual"] = _to_float_list(mig_a.tolist(), 4)
    conv["migratory"]["predicted"] = _to_float_list(mig_p.tolist(), 4)
    conv["resident"]["actual"] = _to_float_list(res_a.tolist(), 4)
    conv["resident"]["predicted"] = _to_float_list(res_p.tolist(), 4)

    # Ensemble metrics
    by_class = {k: _metrics(np.asarray(actual_by_class[k]), np.asarray(predicted_by_class[k])) for k in class_keys}
    ensemble = {
        "rmse": round(float(np.mean([by_class[k]["rmse"] for k in class_keys])), 6),
        "mae": round(float(np.mean([by_class[k]["mae"] for k in class_keys])), 6),
        "r2": round(float(np.mean([by_class[k]["r2"] for k in class_keys])), 6),
    }

    # SHAP grouped importance by filter
    shap_out = {}
    for f in filter_keys:
        group_scores = shap_importance_by_filter.get(f, {})
        shap_out[f] = {
            "labels": group_labels,
            "values": _to_float_list([float(group_scores.get(label, 0.0)) for label in group_labels], 6),
        }

    return {
        "schema_version": "1.0",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "xgboostFeatureImportance": shap_out,
        "convlstmPredictions": conv,
        "ensembleMetrics": {
            "ensemble_average": ensemble,
            "by_class": by_class,
        },
    }


def save_diagnostics_payload(payload: Dict, output_path: str) -> None:
    out = Path(output_path)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(payload, indent=2), encoding="utf-8")


if __name__ == "__main__":
    # Example usage hook: replace with real values from training/evaluation pipeline.
    # This keeps script runnable and documents required structure.
    years = [2020, 2021, 2022, 2023, 2024]
    actual = {
        "tolerant": [10, 11, 12, 12, 13],
        "sensitive": [3, 4, 4, 5, 5],
        "resident": [9, 10, 11, 11, 12],
        "migrant": [4, 4, 5, 5, 6],
    }
    predicted = {
        "tolerant": [9.8, 11.2, 11.9, 12.1, 12.7],
        "sensitive": [3.2, 3.9, 4.1, 4.8, 5.1],
        "resident": [8.7, 10.1, 10.9, 11.3, 11.8],
        "migrant": [4.1, 3.8, 5.0, 5.2, 5.9],
    }
    shap = {
        "all": {"Artificial Light": 0.31, "NDVI": 0.24, "Temperature": 0.13, "Precipitation": 0.08, "Seasonality": 0.07, "Land Cover": 0.09, "Historical Species": 0.08},
        "light_sensitive": {"Artificial Light": 0.36, "NDVI": 0.23, "Temperature": 0.11, "Precipitation": 0.07, "Seasonality": 0.07, "Land Cover": 0.07, "Historical Species": 0.09},
        "light_tolerant": {"Artificial Light": 0.26, "NDVI": 0.26, "Temperature": 0.15, "Precipitation": 0.08, "Seasonality": 0.07, "Land Cover": 0.10, "Historical Species": 0.08},
        "migratory": {"Artificial Light": 0.28, "NDVI": 0.25, "Temperature": 0.12, "Precipitation": 0.10, "Seasonality": 0.08, "Land Cover": 0.08, "Historical Species": 0.09},
        "resident": {"Artificial Light": 0.29, "NDVI": 0.24, "Temperature": 0.14, "Precipitation": 0.08, "Seasonality": 0.07, "Land Cover": 0.09, "Historical Species": 0.09},
    }

    payload = build_diagnostics_payload(years, actual, predicted, shap)
    save_diagnostics_payload(payload, str(Path(__file__).resolve().parents[1] / "api_models" / "diagnostics_precomputed.json"))
    print("Wrote api_models/diagnostics_precomputed.json")
