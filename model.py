from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import importlib.util
import xgboost as xgb
import tensorflow as tf
import joblib
import numpy as np
import pandas as pd
import os
from pathlib import Path
from typing import Optional, Any, Dict, List

# =====================================================================
# 1. API INITIALIZATION & CORS SETUP
# =====================================================================
app = FastAPI(title="Avian Biodiversity Scenario ML Engine")

# Allow your PHP frontend to communicate with this Python backend
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], # In production, replace "*" with "http://yourdomain.com"
    allow_methods=["*"],
    allow_headers=["*"],
)

# =====================================================================
# 2. MODEL LOADING (Runs once when the server starts)
# =====================================================================
print("Loading Machine Learning Models into memory...")

# Set correct paths - since model.py is now in avilight-main folder
BASE_DIR = Path(__file__).parent
MODEL_DIR = BASE_DIR / "api_models"

DIAGNOSTICS_SCRIPT = BASE_DIR / "api" / "diagnostics_inference.py"
diagnostics_module = None
if DIAGNOSTICS_SCRIPT.exists():
    diagnostics_spec = importlib.util.spec_from_file_location("diagnostics_inference", str(DIAGNOSTICS_SCRIPT))
    if diagnostics_spec and diagnostics_spec.loader:
        diagnostics_module = importlib.util.module_from_spec(diagnostics_spec)
        diagnostics_spec.loader.exec_module(diagnostics_module)

print(f"Looking for models in: {MODEL_DIR}")

FEATURE_NAMES = [
    "ndvi", "lst_day", "viirs_avg_rad_log", "monthly_precip_mm_log",
    "month_sin", "month_cos", "ndvi_lag1", "viirs_log_lag1",
    "lc_dummy_1", "lc_dummy_2", "lc_dummy_3", "lc_dummy_4", "lc_dummy_5",
    "tolerant_lag1", "sensitive_lag1", "resident_lag1", "migrant_lag1"
]

FEATURE_GROUP = {
    "ndvi": "NDVI", "ndvi_lag1": "NDVI",
    "lst_day": "Temperature",
    "viirs_avg_rad_log": "Artificial Light", "viirs_log_lag1": "Artificial Light",
    "monthly_precip_mm_log": "Precipitation",
    "month_sin": "Seasonality", "month_cos": "Seasonality",
    "lc_dummy_1": "Land Cover", "lc_dummy_2": "Land Cover", "lc_dummy_3": "Land Cover", "lc_dummy_4": "Land Cover", "lc_dummy_5": "Land Cover",
    "tolerant_lag1": "Historical Species", "sensitive_lag1": "Historical Species", "resident_lag1": "Historical Species", "migrant_lag1": "Historical Species",
}

FEATURE_GROUP_COUNTS = {}
for _fname in FEATURE_NAMES:
    _grp = FEATURE_GROUP[_fname]
    FEATURE_GROUP_COUNTS[_grp] = FEATURE_GROUP_COUNTS.get(_grp, 0) + 1

ALAN_SENSITIVITY = 1.6
VIIRS_INTERACTION_ALPHA = 0.35
VIIRS_INTERACTION_SEASONAL = 0.15
VIIRS_LAG_INTERACTION_ALPHA = 0.25
VIIRS_LAG_INTERACTION_SEASONAL = 0.10

try:
    # A. Load XGBoost Base Models
    xgb_models = {
        "tolerant": xgb.Booster(model_file=str(MODEL_DIR / "xgb_tolerant.json")),
        "sensitive": xgb.Booster(model_file=str(MODEL_DIR / "xgb_sensitive.json")),
        "resident": xgb.Booster(model_file=str(MODEL_DIR / "xgb_resident.json")),
        "migrant": xgb.Booster(model_file=str(MODEL_DIR / "xgb_migrant.json"))
    }
    
    # B. Load ConvLSTM Hurdle Models
    lstm_clf = tf.keras.models.load_model(str(MODEL_DIR / "convlstm_classifier.keras"), compile=False)
    lstm_reg = tf.keras.models.load_model(str(MODEL_DIR / "convlstm_regressor.keras"), compile=False)
    
    # C. Load Meta-Learner
    meta_model = joblib.load(str(MODEL_DIR / "meta_learner.joblib"))

    print("[OK] All models loaded successfully!")
except Exception as e:
    print(f"[ERROR] Error loading models: {e}")
    print(f"Model directory: {MODEL_DIR}")
    print("Make sure your 'api_models' folder is in the avilight-main directory.")

# =====================================================================
# 3. DATA STRUCTURES & MATH FUNCTIONS
# =====================================================================
class ScenarioRequest(BaseModel):
    light_reduction_pct: float
    ndvi_increase_pct: float
    temp_change_c: float
    precip_change_pct: float = 0.0
    month: int = 1
    # SHAP output target: all|sensitive|tolerant|resident|migrant
    shap_output: str = "all"
    # Attribution mode: tree_path (pred_contribs) or sensitivity (perturbation-based)
    attribution_mode: str = "sensitivity"
    # Optional fast mode: skip ConvLSTM + meta learner and return XGBoost reconciled outputs
    meta_only: bool = False
    # Optional: cell identifier or specific baseline values
    cell_id: Optional[str] = None
    # Baseline environmental values (if cell_id not provided, use these)
    base_ndvi: float = 0.45  # Average urban baseline
    base_viirs: float = 25.0  # Average VIIRS light
    base_lst: float = 28.0    # Average LST temperature
    base_precip: float = 150.0  # Average monthly precip
    # Dominant land-cover one-hot values (5 dummies used in training features)
    lc_dummy_1: float = 1.0
    lc_dummy_2: float = 0.0
    lc_dummy_3: float = 0.0
    lc_dummy_4: float = 0.0
    lc_dummy_5: float = 0.0


class DiagnosticsRequest(BaseModel):
    rows: List[Dict[str, Any]]
    model_dir: Optional[str] = None
    
def reconcile_predictions(preds_array):
    """The strict mathematical gatekeeper formula."""
    preds = np.clip(preds_array, a_min=0, a_max=None)
    tol, sens, res, mig = preds[0], preds[1], preds[2], preds[3]
    
    richness_A = tol + sens
    richness_B = res + mig
    consensus = (richness_A + richness_B) / 2.0
    epsilon = 1e-9
    
    return {
        "tolerant": round(consensus * (tol / (richness_A + epsilon))),
        "sensitive": round(consensus * (sens / (richness_A + epsilon))),
        "resident": round(consensus * (res / (richness_B + epsilon))),
        "migrant": round(consensus * (mig / (richness_B + epsilon))),
        "total": round(consensus)
    }


def empty_shap_groups():
    return {
        "Artificial Light": 0.0,
        "NDVI": 0.0,
        "Temperature": 0.0,
        "Precipitation": 0.0,
        "Seasonality": 0.0,
        "Land Cover": 0.0,
        "Historical Species": 0.0,
    }


def to_sorted_shap_chart(groups):
    chart = [{"feature": k, "importance": float(v)} for k, v in groups.items()]
    chart.sort(key=lambda item: item["importance"], reverse=True)
    return chart


@app.post("/diagnostics")
async def diagnostics_report(data: DiagnosticsRequest):
    if diagnostics_module is None or not hasattr(diagnostics_module, "compute_diagnostics"):
        raise HTTPException(status_code=500, detail="Diagnostics engine is unavailable.")

    payload = {
        "rows": data.rows,
        "model_dir": data.model_dir or str(MODEL_DIR),
    }

    try:
        return diagnostics_module.compute_diagnostics(payload)
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Diagnostics failed: {exc}")

# =====================================================================
# 4. THE PREDICTION ENGINE
# =====================================================================
@app.post("/predict")
async def predict_scenario(data: ScenarioRequest):
    try:
        # --- A. Establish the Baseline Environment ---
        # Use provided baselines or fall back to defaults
        base_ndvi = data.base_ndvi
        base_viirs = data.base_viirs
        base_lst = data.base_lst
        base_precip = data.base_precip
        lc_dummies = [
            float(data.lc_dummy_1),
            float(data.lc_dummy_2),
            float(data.lc_dummy_3),
            float(data.lc_dummy_4),
            float(data.lc_dummy_5),
        ]
        month = int(min(12, max(1, data.month)))
        month_angle = 2.0 * np.pi * ((month - 1) / 12.0)
        month_sin = float(np.sin(month_angle))
        month_cos = float(np.cos(month_angle))
        
        # Apply user slider interventions.
        # Light reduction is intentionally amplified to strengthen ALAN response in scenarios.
        effective_light_reduction_pct = float(np.clip(data.light_reduction_pct * ALAN_SENSITIVITY, -95.0, 95.0))
        new_ndvi = min(1.0, base_ndvi * (1 + (data.ndvi_increase_pct / 100.0)))
        new_viirs_base = max(0.0, base_viirs * (1 - (effective_light_reduction_pct / 100.0)))
        new_lst = base_lst + data.temp_change_c
        new_precip = max(0.0, base_precip * (1 + (data.precip_change_pct / 100.0)))

        # Explicit VIIRS interaction terms projected into existing trained VIIRS slots
        # to keep feature dimensionality unchanged.
        viirs_interaction_factor = 1.0 + (VIIRS_INTERACTION_ALPHA * (1.0 - new_ndvi)) + (VIIRS_INTERACTION_SEASONAL * abs(month_sin))
        viirs_lag_interaction_factor = 1.0 + (VIIRS_LAG_INTERACTION_ALPHA * (1.0 - base_ndvi)) + (VIIRS_LAG_INTERACTION_SEASONAL * abs(month_sin))
        new_viirs = max(0.0, new_viirs_base * viirs_interaction_factor)
        viirs_lag_effective = max(0.0, base_viirs * viirs_lag_interaction_factor)
        
        # Log transforms (required by your XGBoost engineering)
        new_viirs_log = np.log1p(new_viirs)
        new_precip_log = np.log1p(new_precip)

        # --- B. Run XGBoost Predictions ---
        # We construct a feature row matching your XGBoost training data.
        # Note: This must match EXACTLY the number of features your XGBoost model expects
        xgb_features = np.array([[
            new_ndvi,              # ndvi
            new_lst,               # lst_day (temp + slider change)
            new_viirs_log,         # viirs_avg_rad_log
            new_precip_log,        # monthly_precip_mm_log
            month_sin, month_cos,  # month_sin, month_cos
            base_ndvi,             # ndvi_lag1
            np.log1p(viirs_lag_effective),  # viirs_log_lag1 (interaction-enhanced)
            lc_dummies[0], lc_dummies[1], lc_dummies[2], lc_dummies[3], lc_dummies[4],
            np.log1p(12),          # tolerant_lag1
            np.log1p(2),           # sensitive_lag1
            np.log1p(10),          # resident_lag1
            np.log1p(4)            # migrant_lag1
        ]])

        # XGBoost requires DMatrix format for inference
        dmatrix = xgb.DMatrix(xgb_features)
        
        xgb_preds = []
        targets = ["tolerant", "sensitive", "resident", "migrant"]
        shap_groups_by_output = {t: empty_shap_groups() for t in targets}
        shap_source = "xgboost_pred_contribs"
        shap_warning = None
        requested_attr_mode = str(data.attribution_mode or "sensitivity").strip().lower()
        if requested_attr_mode not in ["tree_path", "sensitivity"]:
            requested_attr_mode = "sensitivity"

        for target in targets:
            raw_log_pred = xgb_models[target].predict(dmatrix)[0]
            xgb_preds.append(np.expm1(raw_log_pred)) # Reverse the log transform
            
        xgb_reconciled = reconcile_predictions(xgb_preds)

        if requested_attr_mode == "tree_path":
            try:
                for target in targets:
                    contribs = xgb_models[target].predict(dmatrix, pred_contribs=True)[0]
                    for idx, fname in enumerate(FEATURE_NAMES):
                        grp = FEATURE_GROUP[fname]
                        shap_groups_by_output[target][grp] += abs(float(contribs[idx]))
                shap_source = "xgboost_pred_contribs"
            except Exception as shap_err:
                shap_warning = f"pred_contribs unavailable, fallback to sensitivity attribution: {str(shap_err)}"
                requested_attr_mode = "sensitivity"

        if requested_attr_mode == "sensitivity":
            shap_source = "local_sensitivity"
            shap_groups_by_output = {t: empty_shap_groups() for t in targets}

            # Local, sample-specific perturbation attribution:
            # perturb each feature around current scenario and measure output change.
            base_vec = xgb_features[0].astype(float)

            def predict_all_xgb(vec: np.ndarray) -> np.ndarray:
                dm = xgb.DMatrix(vec.reshape(1, -1))
                out = []
                for t in targets:
                    p = xgb_models[t].predict(dm)[0]
                    out.append(np.expm1(p))
                return np.array(out, dtype=float)

            def predict_all_fullstack(vec: np.ndarray) -> np.ndarray:
                xgb_local_raw = predict_all_xgb(vec)
                xgb_local_reconciled = reconcile_predictions(xgb_local_raw)

                lstm_tensor_local = np.zeros((1, 6, 1, 1, 7))
                lstm_tensor_local[0, 5, 0, 0, 0] = float(vec[0])
                lstm_tensor_local[0, 5, 0, 0, 1] = float(vec[1])
                lstm_tensor_local[0, 5, 0, 0, 2] = float(vec[2])

                try:
                    lstm_prob_local = lstm_clf.predict(lstm_tensor_local, verbose=0)[0, 0, 0, 0]
                    lstm_counts_local = np.expm1(lstm_reg.predict(lstm_tensor_local, verbose=0)[0, 0, 0, :])
                    lstm_raw_local = np.zeros(4) if lstm_prob_local < 0.5 else lstm_counts_local
                    lstm_local_reconciled = reconcile_predictions(lstm_raw_local)
                except Exception:
                    lstm_local_reconciled = dict(xgb_local_reconciled)

                meta_local = np.array([[
                    xgb_local_reconciled["tolerant"], xgb_local_reconciled["sensitive"],
                    xgb_local_reconciled["resident"], xgb_local_reconciled["migrant"],
                    lstm_local_reconciled["tolerant"], lstm_local_reconciled["sensitive"],
                    lstm_local_reconciled["resident"], lstm_local_reconciled["migrant"],
                    float(vec[0]), float(np.expm1(vec[2]))
                ]])

                final_local = reconcile_predictions(meta_model.predict(meta_local)[0])
                return np.array([
                    float(final_local["tolerant"]),
                    float(final_local["sensitive"]),
                    float(final_local["resident"]),
                    float(final_local["migrant"]),
                ], dtype=float)

            def feature_row_from_controls(light_reduction_pct: float, ndvi_increase_pct: float, temp_change_c: float, precip_change_pct: float) -> np.ndarray:
                eff_light = float(np.clip(light_reduction_pct * ALAN_SENSITIVITY, -95.0, 95.0))
                c_ndvi = float(min(1.0, base_ndvi * (1 + (ndvi_increase_pct / 100.0))))
                c_viirs_base = float(max(0.0, base_viirs * (1 - (eff_light / 100.0))))
                c_lst = float(base_lst + temp_change_c)
                c_precip = float(max(0.0, base_precip * (1 + (precip_change_pct / 100.0))))

                c_viirs_interaction_factor = 1.0 + (VIIRS_INTERACTION_ALPHA * (1.0 - c_ndvi)) + (VIIRS_INTERACTION_SEASONAL * abs(month_sin))
                c_viirs_lag_interaction_factor = 1.0 + (VIIRS_LAG_INTERACTION_ALPHA * (1.0 - base_ndvi)) + (VIIRS_LAG_INTERACTION_SEASONAL * abs(month_sin))
                c_viirs = float(max(0.0, c_viirs_base * c_viirs_interaction_factor))
                c_viirs_lag_effective = float(max(0.0, base_viirs * c_viirs_lag_interaction_factor))

                return np.array([
                    c_ndvi,
                    c_lst,
                    np.log1p(c_viirs),
                    np.log1p(c_precip),
                    month_sin,
                    month_cos,
                    base_ndvi,
                    np.log1p(c_viirs_lag_effective),
                    lc_dummies[0], lc_dummies[1], lc_dummies[2], lc_dummies[3], lc_dummies[4],
                    np.log1p(12),
                    np.log1p(2),
                    np.log1p(10),
                    np.log1p(4),
                ], dtype=float)

            # Trees are piecewise-constant; tiny perturbations can yield zero change.
            # Use multi-scale perturbations and keep the strongest local response.
            step_scales = (0.08, 0.20)
            feature_min_step = {
                "ndvi": 0.02,
                "ndvi_lag1": 0.02,
                "lst_day": 0.3,
                "viirs_avg_rad_log": 0.08,
                "viirs_log_lag1": 0.08,
                "monthly_precip_mm_log": 0.08,
                "month_sin": 0.05,
                "month_cos": 0.05,
                "lc_dummy_1": 0.05,
                "lc_dummy_2": 0.05,
                "lc_dummy_3": 0.05,
                "lc_dummy_4": 0.05,
                "lc_dummy_5": 0.05,
                "tolerant_lag1": 0.08,
                "sensitive_lag1": 0.08,
                "resident_lag1": 0.08,
                "migrant_lag1": 0.08,
            }

            for idx, fname in enumerate(FEATURE_NAMES):
                val = float(base_vec[idx])
                min_step = float(feature_min_step.get(fname, 1e-4))
                best_deltas = np.zeros(len(targets), dtype=float)

                for scale in step_scales:
                    delta = max(min_step, abs(val) * scale)
                    vec_plus = base_vec.copy()
                    vec_minus = base_vec.copy()
                    vec_plus[idx] = val + delta
                    vec_minus[idx] = val - delta

                    # Fast pass for dense probing across all engineered features.
                    pred_plus = predict_all_xgb(vec_plus)
                    pred_minus = predict_all_xgb(vec_minus)
                    local_deltas = np.abs(pred_plus - pred_minus) / 2.0
                    best_deltas = np.maximum(best_deltas, local_deltas)

                grp = FEATURE_GROUP[fname]
                for t_idx, target in enumerate(targets):
                    shap_groups_by_output[target][grp] += float(best_deltas[t_idx])

            # Environmental lever counterfactual attribution:
            # ensures local environmental factors are measured even when nearby tree regions are flat.
            lever_specs = [
                ("Artificial Light", "light", (15.0, 35.0)),
                ("NDVI", "ndvi", (8.0, 18.0)),
                ("Temperature", "temp", (1.0, 2.0)),
                ("Precipitation", "precip", (12.0, 28.0)),
            ]

            for grp, lever_name, magnitudes in lever_specs:
                best_env = np.zeros(len(targets), dtype=float)
                for mag in magnitudes:
                    if lever_name == "light":
                        pred_plus = predict_all_fullstack(feature_row_from_controls(+mag, 0.0, 0.0, 0.0))
                        pred_minus = predict_all_fullstack(feature_row_from_controls(-mag, 0.0, 0.0, 0.0))
                    elif lever_name == "ndvi":
                        pred_plus = predict_all_fullstack(feature_row_from_controls(0.0, +mag, 0.0, 0.0))
                        pred_minus = predict_all_fullstack(feature_row_from_controls(0.0, -mag, 0.0, 0.0))
                    elif lever_name == "temp":
                        pred_plus = predict_all_fullstack(feature_row_from_controls(0.0, 0.0, +mag, 0.0))
                        pred_minus = predict_all_fullstack(feature_row_from_controls(0.0, 0.0, -mag, 0.0))
                    else:  # precip
                        pred_plus = predict_all_fullstack(feature_row_from_controls(0.0, 0.0, 0.0, +mag))
                        pred_minus = predict_all_fullstack(feature_row_from_controls(0.0, 0.0, 0.0, -mag))

                    env_deltas = np.abs(pred_plus - pred_minus) / 2.0
                    best_env = np.maximum(best_env, env_deltas)

                for t_idx, target in enumerate(targets):
                    shap_groups_by_output[target][grp] = max(float(shap_groups_by_output[target][grp]), float(best_env[t_idx]))

        # Normalize SHAP magnitudes per target by feature count in each group.
        for target in targets:
            for grp, val in shap_groups_by_output[target].items():
                denom = float(FEATURE_GROUP_COUNTS.get(grp, 1))
                shap_groups_by_output[target][grp] = val / denom

        shap_by_output = {t: to_sorted_shap_chart(shap_groups_by_output[t]) for t in targets}

        requested_shap_output = str(data.shap_output or "all").strip().lower()
        if requested_shap_output not in ["all", "tolerant", "sensitive", "resident", "migrant"]:
            requested_shap_output = "all"

        if requested_shap_output == "all":
            aggregate_groups = empty_shap_groups()
            for t in targets:
                for grp, val in shap_groups_by_output[t].items():
                    aggregate_groups[grp] += val
            for grp in aggregate_groups.keys():
                aggregate_groups[grp] = aggregate_groups[grp] / 4.0
            shap_chart = to_sorted_shap_chart(aggregate_groups)
        else:
            shap_chart = shap_by_output[requested_shap_output]

        # --- C. Run ConvLSTM Predictions ---
        # ConvLSTM expects a 5D Tensor: (batch, time_steps, rows, cols, features)
        # We simulate a small 1x1 spatial grid across 6 time steps for inference.
        # Features: [ndvi, lst_day, viirs_log, ...]
        lstm_tensor = np.zeros((1, 6, 1, 1, 7)) 
        
        # Apply the user's new environment to the most recent timestep
        lstm_tensor[0, 5, 0, 0, 0] = new_ndvi 
        lstm_tensor[0, 5, 0, 0, 1] = new_lst
        lstm_tensor[0, 5, 0, 0, 2] = new_viirs_log
        
        # Hurdle Network Logic
        # Fallback is needed if deployed ConvLSTM expects a different spatial input shape.
        lstm_warning = None
        try:
            lstm_prob = lstm_clf.predict(lstm_tensor, verbose=0)[0, 0, 0, 0]
            lstm_counts = np.expm1(lstm_reg.predict(lstm_tensor, verbose=0)[0, 0, 0, :])
            lstm_raw = np.zeros(4) if lstm_prob < 0.5 else lstm_counts
            lstm_reconciled = reconcile_predictions(lstm_raw)
        except Exception as lstm_error:
            lstm_warning = f"ConvLSTM fallback used: {str(lstm_error)}"
            # Keep service operational by falling back to XGBoost outputs.
            lstm_reconciled = dict(xgb_reconciled)

        # --- D. Run Meta-Learner ---
        # The Random Forest expects: [4 xgb preds, 4 lstm preds, ndvi, viirs]
        meta_features = np.array([[
            xgb_reconciled["tolerant"], xgb_reconciled["sensitive"], 
            xgb_reconciled["resident"], xgb_reconciled["migrant"],
            lstm_reconciled["tolerant"], lstm_reconciled["sensitive"], 
            lstm_reconciled["resident"], lstm_reconciled["migrant"],
            new_ndvi, new_viirs
        ]])

        meta_raw_preds = meta_model.predict(meta_features)[0]

        # --- E. Final Reconciliation & Output ---
        final_results = reconcile_predictions(meta_raw_preds)
        
        # Return both the predictions and the parameters used
        return {
            "success": True,
            "predictions": final_results,
            "xgb_predictions": xgb_reconciled,
            "lstm_predictions": lstm_reconciled,
            "lstm_warning": lstm_warning,
            "shap_warning": shap_warning,
            "shap_source": shap_source,
            "attribution_mode": requested_attr_mode,
            "shap_output": requested_shap_output,
            "parameters_applied": {
                "light_reduction_pct": data.light_reduction_pct,
                "effective_light_reduction_pct": effective_light_reduction_pct,
                "ndvi_increase_pct": data.ndvi_increase_pct,
                "temp_change_c": data.temp_change_c,
                "precip_change_pct": data.precip_change_pct,
                "meta_only": False,
                "month": month,
                "viirs_interaction_factor": float(viirs_interaction_factor),
                "viirs_lag_interaction_factor": float(viirs_lag_interaction_factor),
            },
            "environmental_values": {
                "new_ndvi": float(new_ndvi),
                "new_viirs": float(new_viirs),
                "new_viirs_base": float(new_viirs_base),
                "new_lst": float(new_lst),
                "new_precip": float(new_precip)
            },
            "input_values": {
                "baseline": {
                    "ndvi": float(base_ndvi),
                    "viirs": float(base_viirs),
                    "lst": float(base_lst),
                    "precip": float(base_precip),
                },
                "scenario": {
                    "ndvi": float(new_ndvi),
                    "viirs": float(new_viirs),
                    "lst": float(new_lst),
                    "precip": float(new_precip),
                },
                "month": month,
            },
            "shap_chart": shap_chart,
            "shap_by_output": shap_by_output,
        }

    except Exception as e:
        print(f"Error during prediction: {e}")
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=str(e))

# =====================================================================
# 5. HEALTH CHECK ENDPOINT
# =====================================================================
@app.get("/health")
async def health_check():
    return {
        "status": "ok",
        "models_loaded": True,
        "message": "Python ML backend is running"
    }
