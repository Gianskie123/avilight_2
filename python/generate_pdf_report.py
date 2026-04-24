#!/usr/bin/env python3
"""AviLight PDF/CSV report generator.

Reads JSON payload from stdin and writes selected output file.
"""

import argparse
import csv
import io
import json
import os
import sys
import tempfile
from datetime import datetime, timezone
from typing import Any, Dict, Iterable, List, Tuple

try:
    from fpdf import FPDF
except Exception:
    FPDF = None

try:
    import matplotlib

    matplotlib.use("Agg")
    import matplotlib.pyplot as plt

    MATPLOTLIB_AVAILABLE = True
except Exception:
    MATPLOTLIB_AVAILABLE = False


def safe_list(v: Any) -> List[Any]:
    return v if isinstance(v, list) else []


def safe_dict(v: Any) -> Dict[str, Any]:
    return v if isinstance(v, dict) else {}


def section_enabled(selected: List[str], key: str) -> bool:
    """Return True when key is in selected, or selected is empty (= all sections)."""
    return not selected or key in selected


def fmt_area(filters: Dict[str, Any]) -> str:
    return str(filters.get("selected_area") or "All Areas")


def fmt_trend_range(filters: Dict[str, Any]) -> str:
    return f"{filters.get('start_year', '-')} to {filters.get('end_year', '-')}"


def fmt_snapshot_range(filters: Dict[str, Any]) -> str:
    sy = filters.get("snapshot_start_year") or filters.get("snapshot_year", "-")
    ey = filters.get("snapshot_end_year") or filters.get("snapshot_year", "-")
    try:
        sm = int(filters.get("snapshot_start_month") or filters.get("snapshot_month") or 0)
        em = int(filters.get("snapshot_end_month") or filters.get("snapshot_month") or 0)
        if sm and em:
            return f"{sy}-{sm:02d} to {ey}-{em:02d}"
    except Exception:
        pass
    return f"{sy} to {ey}"


def fmt_num(v: Any, dp: int = 4) -> str:
    if v is None or v == "":
        return "-"
    try:
        return f"{float(v):.{dp}f}"
    except Exception:
        return str(v)


def sanitize_for_pdf(text: Any) -> str:
    if text is None:
        return ""
    s = str(text)
    s = s.replace("–", "-").replace("—", "-").replace("…", "...")
    out = []
    for ch in s:
        out.append(ch if ord(ch) <= 255 else "?")
    return "".join(out)


def get_kba_pa_audit_rows(payload: Dict[str, Any]) -> List[Dict[str, Any]]:
    rows = [r for r in safe_list(payload.get("kbaPaAuditRows")) if isinstance(r, dict)]
    if rows:
        return rows

    kba_path = os.path.join(os.path.dirname(__file__), "..", "data", "sample_kba.json")
    if not os.path.isfile(kba_path):
        return []

    try:
        with open(kba_path, "r", encoding="utf-8") as fh:
            legacy = json.load(fh)
    except Exception:
        return []

    out: List[Dict[str, Any]] = []
    for row in safe_list(legacy):
        if not isinstance(row, dict):
            continue
        out.append(
            {
                "name": row.get("name", ""),
                "type": row.get("type", ""),
                "species_count": row.get("species_count"),
                "sensitive_species_count": row.get("sensitive_species_count"),
                "sensitive_species_percent": row.get("sensitive_species_percent"),
                "light_exposure": row.get("light_exposure"),
                "mean_ndvi": row.get("mean_ndvi"),
                "max_lst": row.get("max_lst"),
                "precipitation_total": row.get("precipitation_total"),
                "grid_cell_count": row.get("grid_cell_count"),
                "effectiveness_score": row.get("effectiveness_score"),
                "status": row.get("status", ""),
                "snapshot_year": row.get("snapshot_year"),
                "snapshot_month": row.get("snapshot_month"),
            }
        )
    return out


def read_payload() -> Dict[str, Any]:
    raw = io.TextIOWrapper(buffer=sys.stdin.buffer, encoding="utf-8").read()
    if not raw.strip():
        return {}
    payload = json.loads(raw)
    return payload if isinstance(payload, dict) else {}


def write_csv(payload: Dict[str, Any], output_path: str) -> None:
    filters = safe_dict(payload.get("filters"))
    trend = safe_dict(payload.get("trendHistoricalData"))
    corr = safe_dict(payload.get("trendCorrelationData"))
    snapshot = safe_dict(payload.get("snapshotDistributions"))
    scatter = safe_dict(payload.get("snapshotScatterData"))
    top_sites = safe_dict(payload.get("topSitesRichnessData"))
    metrics = safe_dict(safe_dict(payload.get("ensembleMetrics")).get("ensemble_average"))
    xgboost = safe_dict(payload.get("xgboostFeatureImportance"))
    convlstm = safe_dict(payload.get("convlstmPredictions"))
    kba_rows = get_kba_pa_audit_rows(payload)
    selected_sections = safe_list(payload.get("selected_sections"))

    labels = safe_list(trend.get("labels"))
    richness = safe_list(trend.get("richness"))
    viirs = safe_list(trend.get("viirs"))
    ndvi = safe_list(trend.get("ndvi"))
    lst = safe_list(trend.get("lst"))
    precip = safe_list(trend.get("precip"))

    area_str = fmt_area(filters)
    trend_range_str = fmt_trend_range(filters)
    snap_range_str = fmt_snapshot_range(filters)

    def section(w: csv.writer, title: str) -> None:
        w.writerow([])
        w.writerow(["=" * 72])
        w.writerow([title])
        w.writerow(["=" * 72])

    with open(output_path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["AviLight Statistical Reports Export"])
        w.writerow(["Generated At", datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")])
        w.writerow(["Area", area_str])
        w.writerow(["Trend Range", trend_range_str])
        w.writerow(["Snapshot Range", snap_range_str])

        if section_enabled(selected_sections, "historical_trends"):
            section(w, "LONG-TERM ECOLOGICAL IMPACT TRENDS")
            w.writerow(["Area", area_str])
            w.writerow(["Trend Range", trend_range_str])
            w.writerow(["Year", "Bird Richness", "ALAN (VIIRS)", "NDVI", "LST", "Precipitation"])
            n = max(len(labels), len(richness), len(viirs), len(ndvi), len(lst), len(precip))
            for i in range(n):
                w.writerow([
                    labels[i] if i < len(labels) else "",
                    richness[i] if i < len(richness) else "",
                    viirs[i] if i < len(viirs) else "",
                    ndvi[i] if i < len(ndvi) else "",
                    lst[i] if i < len(lst) else "",
                    precip[i] if i < len(precip) else "",
                ])

        if section_enabled(selected_sections, "correlation_matrix"):
            section(w, "ECOLOGICAL IMPACT - CORRELATION MATRIX")
            w.writerow(["Area", area_str])
            w.writerow(["Trend Range", trend_range_str])
            w.writerow(["Metric", "Pearson r"])
            w.writerow(["ALAN vs Richness", corr.get("richness_viirs", "")])
            w.writerow(["NDVI vs Richness", corr.get("richness_ndvi", "")])
            w.writerow(["LST vs Richness", corr.get("richness_lst", "")])
            w.writerow(["Precipitation vs Richness", corr.get("richness_precip", "")])

        if section_enabled(selected_sections, "migration_status"):
            migration = safe_dict(snapshot.get("migration_status"))
            mig_labels = safe_list(migration.get("labels"))
            mig_data = safe_list(migration.get("data"))
            section(w, "IMMEDIATE SITE ASSESSMENT - MIGRATION STATUS DISTRIBUTION")
            w.writerow(["Area", area_str])
            w.writerow(["Snapshot Range", snap_range_str])
            w.writerow(["Category", "Count"])
            for i in range(max(len(mig_labels), len(mig_data))):
                w.writerow([
                    mig_labels[i] if i < len(mig_labels) else "",
                    mig_data[i] if i < len(mig_data) else "",
                ])

        if section_enabled(selected_sections, "light_tolerance"):
            light = safe_dict(snapshot.get("light_tolerance"))
            light_labels = safe_list(light.get("labels"))
            light_data = safe_list(light.get("data"))
            section(w, "IMMEDIATE SITE ASSESSMENT - LIGHT TOLERANCE DISTRIBUTION")
            w.writerow(["Area", area_str])
            w.writerow(["Snapshot Range", snap_range_str])
            w.writerow(["Category", "Count"])
            for i in range(max(len(light_labels), len(light_data))):
                w.writerow([
                    light_labels[i] if i < len(light_labels) else "",
                    light_data[i] if i < len(light_data) else "",
                ])

        scatter_defs = [
            ("light_richness", "IMMEDIATE SITE ASSESSMENT - ALAN VS RICHNESS", "alan_richness"),
            ("ndvi_richness", "IMMEDIATE SITE ASSESSMENT - NDVI VS RICHNESS", "ndvi_richness"),
            ("lst_richness", "IMMEDIATE SITE ASSESSMENT - LST VS RICHNESS", "lst_richness"),
            ("precipitation_richness", "IMMEDIATE SITE ASSESSMENT - PRECIPITATION VS RICHNESS", "precipitation_richness"),
        ]
        for key, title, sec_key in scatter_defs:
            if not section_enabled(selected_sections, sec_key):
                continue
            block = safe_dict(scatter.get(key))
            points = [p for p in safe_list(block.get("points")) if isinstance(p, dict)]
            section(w, title)
            w.writerow(["Area", area_str])
            w.writerow(["Snapshot Range", snap_range_str])
            w.writerow(["Site", "Area", "X", "Richness"])
            for p in points:
                w.writerow([
                    p.get("site_name") or p.get("area") or "",
                    p.get("area") or "",
                    p.get("x") or "",
                    p.get("y") or "",
                ])

        if section_enabled(selected_sections, "top_sites"):
            top_rows = [r for r in safe_list(top_sites.get("rows")) if isinstance(r, dict)]
            section(w, "IMMEDIATE SITE ASSESSMENT - TOP SITES BY RICHNESS")
            w.writerow(["Area", area_str])
            w.writerow(["Snapshot Range", snap_range_str])
            w.writerow(["Rank", "Area", "Bird Richness"])
            for r in top_rows:
                w.writerow([
                    r.get("rank", ""),
                    r.get("area", ""),
                    r.get("bird_richness", ""),
                ])

        if section_enabled(selected_sections, "ensemble_metrics"):
            section(w, "MODEL DIAGNOSTICS - ENSEMBLE METRICS")
            w.writerow(["Metric", "Value"])
            w.writerow(["RMSE", metrics.get("rmse", "")])
            w.writerow(["MAE", metrics.get("mae", "")])
            w.writerow(["R2", metrics.get("r2", "")])

        diag_filters = [
            ("all", "All Species"),
            ("light_sensitive", "Light Sensitive"),
            ("light_tolerant", "Light Tolerant"),
            ("migratory", "Migratory"),
            ("resident", "Resident"),
        ]

        if section_enabled(selected_sections, "xgboost_importance"):
            for key, label in diag_filters:
                block = safe_dict(xgboost.get(key))
                imp_labels = safe_list(block.get("labels"))
                imp_vals = safe_list(block.get("values"))
                section(w, f"MODEL DIAGNOSTICS - XGBOOST FEATURE IMPORTANCE ({label})")
                w.writerow(["Feature", "Importance"])
                for i in range(max(len(imp_labels), len(imp_vals))):
                    w.writerow([
                        imp_labels[i] if i < len(imp_labels) else "",
                        imp_vals[i] if i < len(imp_vals) else "",
                    ])

        if section_enabled(selected_sections, "convlstm_predictions"):
            for key, label in diag_filters:
                block = safe_dict(convlstm.get(key))
                years = safe_list(block.get("years"))
                actual = safe_list(block.get("actual"))
                predicted = safe_list(block.get("predicted"))
                section(w, f"MODEL DIAGNOSTICS - CONVLSTM ACTUAL VS PREDICTED ({label})")
                w.writerow(["Year", "Actual", "Predicted"])
                for i in range(max(len(years), len(actual), len(predicted))):
                    w.writerow([
                        years[i] if i < len(years) else "",
                        actual[i] if i < len(actual) else "",
                        predicted[i] if i < len(predicted) else "",
                    ])

        if section_enabled(selected_sections, "kba_pa_audit"):
            section(w, "CONSERVATION PRIORITIES - KEY BIODIVERSITY AREA & PROTECTED AREAS AUDIT TABLE")
            w.writerow(["Area", area_str])
            w.writerow(["Snapshot Range", snap_range_str])
            w.writerow([
                "Site", "Type", "Species Count", "Sensitive Count", "Sensitive %",
                "Light Exposure", "Mean NDVI", "Max LST", "Precipitation Total",
                "Grid Cells", "Effectiveness", "Status", "Snapshot",
            ])
            for row in kba_rows:
                snap_year = row.get("snapshot_year")
                snap_month = row.get("snapshot_month")
                snap = ""
                if snap_year not in (None, ""):
                    if snap_month not in (None, ""):
                        snap = f"{snap_year}-{str(snap_month).zfill(2)}"
                    else:
                        snap = str(snap_year)
                w.writerow([
                    row.get("name", ""),
                    row.get("type", ""),
                    row.get("species_count", ""),
                    row.get("sensitive_species_count", ""),
                    row.get("sensitive_species_percent", ""),
                    row.get("light_exposure", ""),
                    row.get("mean_ndvi", ""),
                    row.get("max_lst", ""),
                    row.get("precipitation_total", ""),
                    row.get("grid_cell_count", ""),
                    row.get("effectiveness_score", ""),
                    row.get("status", ""),
                    snap,
                ])

        if section_enabled(selected_sections, "systems_recommendations"):
            recs = [r for r in safe_list(payload.get("systemsRecommendations")) if isinstance(r, dict)]
            section(w, "CONSERVATION PRIORITIES - RULE-BASED RECOMMENDATIONS")
            w.writerow(["Area", area_str])
            w.writerow(["Snapshot Range", snap_range_str])
            w.writerow(["#", "Title", "Source", "Message", "Evidence"])
            for i, rec in enumerate(recs, 1):
                evidence = "; ".join(str(e) for e in safe_list(rec.get("evidence")) if e)
                w.writerow([
                    i,
                    rec.get("title", ""),
                    rec.get("source", ""),
                    rec.get("message", ""),
                    evidence,
                ])


if FPDF is not None:
    class AviLightPDF(FPDF):
        def __init__(self):
            super().__init__()
            self.title_text = sanitize_for_pdf("AviLight Ecological Impact and Diagnostics Report")

        def header(self):
            self.set_font("Helvetica", "B", 13)
            self.set_text_color(20, 20, 20)
            self.cell(0, 8, self.title_text, 0, 1, "L")
            self.set_draw_color(190, 190, 190)
            self.line(10, 12, 200, 12)
            self.ln(3)

        def footer(self):
            self.set_y(-12)
            self.set_font("Helvetica", "", 8)
            self.set_text_color(90, 90, 90)
            self.cell(0, 8, f"Page {self.page_no()}", 0, 0, "R")

        def section_title(self, title: str) -> None:
            self.set_font("Helvetica", "B", 12)
            self.set_text_color(15, 23, 42)
            self.cell(0, 8, sanitize_for_pdf(title), 0, 1, "L")
            self.set_text_color(0, 0, 0)

        def filter_note(self, text_w: float, lm: float, text: str) -> None:
            self.set_font("Helvetica", "I", 8)
            self.set_text_color(100, 100, 110)
            self.set_x(lm)
            self.cell(text_w, 4, sanitize_for_pdf(text), 0, 1, "L")
            self.set_text_color(0, 0, 0)
            self.ln(1)

        def note(self, text: str) -> None:
            self.set_font("Helvetica", "", 9)
            self.set_x(self.l_margin)
            self.multi_cell(self.w - self.l_margin - self.r_margin, 5, sanitize_for_pdf(text))
            self.ln(1)

        def table_header(self, headers: List[str], widths: List[float]) -> None:
            self.set_font("Helvetica", "B", 8)
            self.set_fill_color(230, 236, 244)
            self.set_draw_color(180, 190, 200)
            table_width = sum(widths)
            start_x = self.l_margin + ((self.w - self.l_margin - self.r_margin - table_width) / 2)
            self.set_x(start_x)
            for h, w in zip(headers, widths):
                self.cell(w, 6, sanitize_for_pdf(h), border=1, align="C", fill=True)
            self.ln()

        def table_row(self, values: List[Any], widths: List[float], numeric_cols: Iterable[int] = ()) -> None:
            self.set_font("Helvetica", "", 7)
            self.set_draw_color(210, 215, 220)
            table_width = sum(widths)
            start_x = self.l_margin + ((self.w - self.l_margin - self.r_margin - table_width) / 2)
            self.set_x(start_x)
            numeric_set = set(numeric_cols)
            for idx, (v, w) in enumerate(zip(values, widths)):
                align = "R" if idx in numeric_set else "L"
                self.cell(w, 5, sanitize_for_pdf(v), border=1, align=align)
            self.ln()

        def ensure_space(self, needed_height: float) -> None:
            if self.get_y() + needed_height > 270:
                self.add_page()
else:
    class AviLightPDF:  # type: ignore[no-redef]
        pass


def _save_figure(path: str) -> str:
    plt.savefig(path, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close()
    return path


def make_historical_chart(payload: Dict[str, Any], tmp_dir: str) -> str:
    if not MATPLOTLIB_AVAILABLE:
        return ""
    trend = safe_dict(payload.get("trendHistoricalData"))
    years = safe_list(trend.get("labels"))
    richness = safe_list(trend.get("richness"))
    viirs = safe_list(trend.get("viirs"))
    ndvi = safe_list(trend.get("ndvi"))
    lst = safe_list(trend.get("lst"))
    precip = safe_list(trend.get("precip"))
    precip_scale_factor = 100.0
    if not years:
        return ""

    fig, ax1 = plt.subplots(figsize=(10.5, 4.8))
    rich_n = min(len(years), len(richness))
    ax1.plot(years[:rich_n], richness[:rich_n], color="#2563eb", marker="o", linewidth=2.2, label="Bird Richness")
    ax1.set_xlabel("Year")
    ax1.set_ylabel("Richness (species)", color="#2563eb")
    ax1.tick_params(axis="y", labelcolor="#2563eb")
    ax1.grid(True, alpha=0.25)

    ax2 = ax1.twinx()
    if viirs:
        n = min(len(years), len(viirs))
        ax2.plot(years[:n], viirs[:n], color="#dc2626", linestyle="--", linewidth=1.8, label="ALAN")
    if ndvi:
        n = min(len(years), len(ndvi))
        ax2.plot(years[:n], ndvi[:n], color="#16a34a", linestyle="--", linewidth=1.8, label="NDVI")
    if lst:
        n = min(len(years), len(lst))
        ax2.plot(years[:n], lst[:n], color="#d97706", linestyle="--", linewidth=1.8, label="LST")
    if precip:
        n = min(len(years), len(precip))
        precip_scaled = []
        for v in precip[:n]:
            try:
                precip_scaled.append(float(v) / precip_scale_factor)
            except Exception:
                precip_scaled.append(0.0)
        ax2.plot(years[:n], precip_scaled, color="#0891b2", linestyle="-", linewidth=1.8, label="Precip (scaled)")
    ax2.set_ylabel("Environmental indicators")

    h1, l1 = ax1.get_legend_handles_labels()
    h2, l2 = ax2.get_legend_handles_labels()
    if h1 or h2:
        ax1.legend(h1 + h2, l1 + l2, loc="upper left", ncol=3, fontsize=8)
    fig.tight_layout()
    return _save_figure(os.path.join(tmp_dir, "historical_trends.png"))


def make_correlation_chart(payload: Dict[str, Any], tmp_dir: str) -> str:
    if not MATPLOTLIB_AVAILABLE:
        return ""
    corr = safe_dict(payload.get("trendCorrelationData"))
    labels = ["ALAN", "NDVI", "LST", "Precip"]
    values = [
        float(corr.get("richness_viirs", 0) or 0),
        float(corr.get("richness_ndvi", 0) or 0),
        float(corr.get("richness_lst", 0) or 0),
        float(corr.get("richness_precip", 0) or 0),
    ]
    fig, ax = plt.subplots(figsize=(9, 3.8))
    ax.barh(labels, values, color=["#dc2626", "#16a34a", "#d97706", "#0891b2"])
    ax.set_xlim(-1, 1)
    ax.set_xlabel("Pearson r")
    ax.set_title("Correlation Matrix")
    ax.grid(True, axis="x", alpha=0.25)
    for i, v in enumerate(values):
        ax.text(v + (0.02 if v >= 0 else -0.02), i, f"{v:.3f}", va="center", ha="left" if v >= 0 else "right", fontsize=8)
    fig.tight_layout()
    return _save_figure(os.path.join(tmp_dir, "correlation.png"))


def make_doughnut_chart(labels: List[str], values: List[float], title: str, colors: List[str], path: str) -> str:
    if not MATPLOTLIB_AVAILABLE:
        return ""
    if not labels or not values or sum(float(v or 0) for v in values) <= 0:
        return ""
    fig, ax = plt.subplots(figsize=(4.8, 4.8))
    wedges, _ = ax.pie(values, labels=labels, colors=colors[: len(values)], startangle=90)
    centre_circle = plt.Circle((0, 0), 0.55, fc="white")
    fig.gca().add_artist(centre_circle)
    ax.set_title(title)
    ax.legend(wedges, labels, loc="lower center", bbox_to_anchor=(0.5, -0.2), ncol=2, fontsize=8)
    fig.tight_layout()
    return _save_figure(path)


def make_scatter_chart(points: List[Dict[str, Any]], title: str, xlabel: str, color: str, path: str) -> str:
    if not MATPLOTLIB_AVAILABLE:
        return ""
    if not points:
        return ""
    xs: List[float] = []
    ys: List[float] = []
    for p in points:
        try:
            xs.append(float(p.get("x", 0)))
            ys.append(float(p.get("y", 0)))
        except Exception:
            continue
    if not xs:
        return ""
    fig, ax = plt.subplots(figsize=(6.2, 4.2))
    ax.scatter(xs, ys, color=color, alpha=0.8, s=28)
    ax.set_xlabel(xlabel)
    ax.set_ylabel("Bird Richness")
    ax.set_title(title)
    ax.grid(True, alpha=0.25)
    fig.tight_layout()
    return _save_figure(path)


def make_top_sites_chart(top_rows: List[Dict[str, Any]], path: str) -> str:
    if not MATPLOTLIB_AVAILABLE:
        return ""
    if not top_rows:
        return ""
    labels = [str(r.get("area", "-")) for r in top_rows]
    vals = [float(r.get("bird_richness", 0) or 0) for r in top_rows]
    fig, ax = plt.subplots(figsize=(9.6, 4.6))
    ax.barh(labels, vals, color="#2563eb", alpha=0.82)
    ax.invert_yaxis()
    ax.set_xlabel("Bird Richness")
    ax.set_title("Top Sites by Richness")
    ax.grid(True, axis="x", alpha=0.25)
    fig.tight_layout()
    return _save_figure(path)


def xgboost_color_for_label(label: Any, index: int) -> str:
    key = str(label or "").lower()
    key = "".join(ch if ch.isalnum() else "_" for ch in key).strip("_")
    color_map = {
        "richness": "#1d4ed8", "richnes": "#1d4ed8", "bird_richness": "#1d4ed8",
        "species_richness": "#1d4ed8", "bird_species_richness": "#1d4ed8",
        "alan": "#ef4444", "viirs": "#ef4444", "ndvi": "#22c55e",
        "lst": "#f97316", "temperature": "#f97316", "land_surface_temperature": "#f97316",
        "precipitation": "#06b6d4", "precip": "#06b6d4", "rainfall": "#06b6d4",
        "seasonality": "#ec4899", "seasonal": "#ec4899",
        "land_cover": "#eab308", "landcover": "#eab308", "land_use": "#eab308",
    }
    fallbacks = ["#e11d48","#06b6d4","#8b5cf6","#f59e0b","#14b8a6","#f472b6","#22c55e","#3b82f6","#a855f7","#84cc16"]
    return color_map.get(key, fallbacks[index % len(fallbacks)])


def make_xgboost_chart(block: Dict[str, Any], title: str, path: str) -> str:
    if not MATPLOTLIB_AVAILABLE:
        return ""
    labels = safe_list(block.get("labels"))
    values = safe_list(block.get("values"))
    if not labels or not values:
        return ""
    n = min(len(labels), len(values))
    labels = [str(v) for v in labels[:n]]
    values = [float(v or 0) for v in values[:n]]
    colors = [xgboost_color_for_label(labels[i], i) for i in range(n)]
    fig, ax = plt.subplots(figsize=(9.2, 4.8))
    ax.barh(labels, values, color=colors, alpha=0.88)
    ax.invert_yaxis()
    ax.set_xlabel("Importance Score")
    ax.set_title(title)
    ax.grid(True, axis="x", alpha=0.25)
    fig.tight_layout()
    return _save_figure(path)


def make_convlstm_chart(block: Dict[str, Any], title: str, path: str) -> str:
    if not MATPLOTLIB_AVAILABLE:
        return ""
    years = safe_list(block.get("years"))
    actual = safe_list(block.get("actual"))
    predicted = safe_list(block.get("predicted"))
    if not years:
        return ""
    fig, ax = plt.subplots(figsize=(9.2, 4.8))
    n_actual = min(len(years), len(actual))
    n_pred = min(len(years), len(predicted))
    ax.plot(years[:n_actual], actual[:n_actual], color="#2563eb", marker="o", linewidth=2.0, label="Actual")
    ax.plot(years[:n_pred], predicted[:n_pred], color="#dc2626", marker="s", linestyle="--", linewidth=2.0, label="Predicted")
    ax.set_xlabel("Year")
    ax.set_ylabel("Species Richness")
    ax.set_title(title)
    ax.legend()
    ax.grid(True, alpha=0.25)
    fig.tight_layout()
    return _save_figure(path)


def write_table_block(
    pdf: AviLightPDF,
    title: str,
    headers: List[str],
    widths: List[float],
    rows: List[List[Any]],
    numeric_cols: Iterable[int] = (),
) -> None:
    pdf.ensure_space(20)
    pdf.set_font("Helvetica", "B", 10)
    pdf.cell(0, 6, sanitize_for_pdf(title), 0, 1, "L")
    pdf.table_header(headers, widths)
    if not rows:
        pdf.table_row(["No data"] + ["" for _ in headers[1:]], widths)
        pdf.ln(2)
        return
    for row in rows:
        pdf.ensure_space(7)
        normalized = [sanitize_for_pdf(v) for v in row]
        pdf.table_row(normalized, widths, numeric_cols=numeric_cols)
    pdf.ln(2)


def place_chart(
    pdf: AviLightPDF,
    path: str,
    title: str,
    *,
    x: float = -1,
    w: float,
    h: float,
    gap_after: float = 4,
) -> None:
    if not path or not os.path.isfile(path):
        return
    pdf.ensure_space(8 + h + gap_after)
    pdf.note(f"Chart: {title}")
    y = pdf.get_y()
    if x < 0:
        x = pdf.l_margin + ((pdf.w - pdf.l_margin - pdf.r_margin - w) / 2)
    pdf.image(path, x=x, y=y, w=w, h=h)
    pdf.set_y(y + h + gap_after)


def write_pdf(payload: Dict[str, Any], output_path: str) -> None:
    if FPDF is None:
        raise RuntimeError("fpdf2 is required. Install with: pip install fpdf2")

    filters = safe_dict(payload.get("filters"))
    trend = safe_dict(payload.get("trendHistoricalData"))
    corr = safe_dict(payload.get("trendCorrelationData"))
    snapshot = safe_dict(payload.get("snapshotDistributions"))
    snapshot_scatter = safe_dict(payload.get("snapshotScatterData"))
    top_sites = safe_dict(payload.get("topSitesRichnessData"))
    ensemble = safe_dict(safe_dict(payload.get("ensembleMetrics")).get("ensemble_average"))
    xgboost = safe_dict(payload.get("xgboostFeatureImportance"))
    convlstm = safe_dict(payload.get("convlstmPredictions"))
    kba_rows = get_kba_pa_audit_rows(payload)
    selected_sections = safe_list(payload.get("selected_sections"))

    area_str = fmt_area(filters)
    trend_range_str = fmt_trend_range(filters)
    snap_range_str = fmt_snapshot_range(filters)

    ecological_keys = [
        "historical_trends", "correlation_matrix", "migration_status", "light_tolerance",
        "alan_richness", "ndvi_richness", "lst_richness", "precipitation_richness", "top_sites",
    ]
    diagnostic_keys = ["ensemble_metrics", "xgboost_importance", "convlstm_predictions"]
    systems_keys = ["kba_pa_audit", "systems_recommendations"]
    has_ecological = any(section_enabled(selected_sections, k) for k in ecological_keys)
    has_diagnostic = any(section_enabled(selected_sections, k) for k in diagnostic_keys)
    has_systems = any(section_enabled(selected_sections, k) for k in systems_keys)

    pdf = AviLightPDF()
    pdf.set_auto_page_break(auto=True, margin=12)
    pdf.add_page()

    lm = pdf.l_margin
    rm = pdf.r_margin
    text_w = pdf.w - lm - rm  # 190 mm

    pdf.section_title("Executive Summary")
    generated = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")
    summary_rows = [
        ["Area", area_str],
        ["Trend Range", trend_range_str],
        ["Snapshot Range", snap_range_str],
        ["Generated", generated],
    ]
    write_table_block(pdf, "Run Context", ["Field", "Value"], [45, 145], summary_rows)

    with tempfile.TemporaryDirectory(prefix="avilight_pdf_") as tmp_dir:

        # ── Ecological Impact ─────────────────────────────────────────────
        if has_ecological:
            pdf.add_page()
            pdf.section_title("Ecological Impact View")

        # Long-Term Ecological Impact Trends
        if section_enabled(selected_sections, "historical_trends"):
            pdf.ensure_space(10)
            pdf.set_font("Helvetica", "B", 10)
            pdf.set_x(lm)
            pdf.cell(text_w, 5, sanitize_for_pdf("Long-Term Ecological Impact Trends"), 0, 1, "L")
            pdf.filter_note(text_w, lm, f"Area: {area_str}  |  Trend range: {trend_range_str}")

            hist_chart = make_historical_chart(payload, tmp_dir)
            place_chart(pdf, hist_chart, "Long-Term Ecological Impact Trends", w=186, h=78)

            years = safe_list(trend.get("labels"))
            richness = safe_list(trend.get("richness"))
            viirs = safe_list(trend.get("viirs"))
            ndvi = safe_list(trend.get("ndvi"))
            lst = safe_list(trend.get("lst"))
            precip = safe_list(trend.get("precip"))
            n = max(len(years), len(richness), len(viirs), len(ndvi), len(lst), len(precip))
            hist_rows: List[List[Any]] = []
            for i in range(n):
                hist_rows.append([
                    years[i] if i < len(years) else "",
                    fmt_num(richness[i], 2) if i < len(richness) else "",
                    fmt_num(viirs[i], 2) if i < len(viirs) else "",
                    fmt_num(ndvi[i], 4) if i < len(ndvi) else "",
                    fmt_num(lst[i], 2) if i < len(lst) else "",
                    fmt_num(precip[i], 2) if i < len(precip) else "",
                ])
            write_table_block(
                pdf,
                "Numerical Values: Historical Trends",
                ["Year", "Richness", "ALAN", "NDVI", "LST", "Precip"],
                [20, 30, 30, 30, 30, 30],
                hist_rows,
                numeric_cols=(1, 2, 3, 4, 5),
            )

        if section_enabled(selected_sections, "correlation_matrix"):
            pdf.ensure_space(10)
            pdf.set_font("Helvetica", "B", 10)
            pdf.set_x(lm)
            pdf.cell(text_w, 5, sanitize_for_pdf("Correlation Matrix"), 0, 1, "L")
            pdf.filter_note(text_w, lm, f"Area: {area_str}  |  Trend range: {trend_range_str}")

            corr_chart = make_correlation_chart(payload, tmp_dir)
            place_chart(pdf, corr_chart, "Correlation Matrix", w=170, h=58)

            corr_rows = [
                ["ALAN vs Richness", fmt_num(corr.get("richness_viirs"), 4)],
                ["NDVI vs Richness", fmt_num(corr.get("richness_ndvi"), 4)],
                ["LST vs Richness", fmt_num(corr.get("richness_lst"), 4)],
                ["Precipitation vs Richness", fmt_num(corr.get("richness_precip"), 4)],
            ]
            write_table_block(
                pdf, "Numerical Values: Correlation Coefficients",
                ["Metric", "Pearson r"], [125, 65], corr_rows, numeric_cols=(1,),
            )

        # Immediate Site Assessment & Vulnerability
        show_mig = section_enabled(selected_sections, "migration_status")
        show_lt = section_enabled(selected_sections, "light_tolerance")
        if show_mig or show_lt:
            pdf.ensure_space(10)
            pdf.set_font("Helvetica", "B", 10)
            pdf.set_x(lm)
            pdf.cell(text_w, 5, sanitize_for_pdf("Immediate Site Assessment & Vulnerability"), 0, 1, "L")
            pdf.filter_note(text_w, lm, f"Area: {area_str}  |  Snapshot range: {snap_range_str}")

            migration = safe_dict(snapshot.get("migration_status"))
            light_tol = safe_dict(snapshot.get("light_tolerance"))
            mig_labels = [str(v) for v in safe_list(migration.get("labels"))]
            mig_data = [float(v or 0) for v in safe_list(migration.get("data"))]
            lt_labels = [str(v) for v in safe_list(light_tol.get("labels"))]
            lt_data = [float(v or 0) for v in safe_list(light_tol.get("data"))]

            mig_path = make_doughnut_chart(
                mig_labels, mig_data, "Migratory Status Distribution",
                ["#0891b2", "#16a34a", "#6b7280"],
                os.path.join(tmp_dir, "migration_dist.png"),
            ) if show_mig else ""
            lt_path = make_doughnut_chart(
                lt_labels, lt_data, "Light Tolerance Distribution",
                ["#dc2626", "#2563eb", "#6b7280"],
                os.path.join(tmp_dir, "light_tolerance_dist.png"),
            ) if show_lt else ""

            has_mig = bool(mig_path and os.path.isfile(mig_path))
            has_lt = bool(lt_path and os.path.isfile(lt_path))
            if has_mig or has_lt:
                chart_h = 56
                pdf.ensure_space(8 + chart_h + 4)
                pdf.note("Chart: Migratory and Light Tolerance Distribution")
                y = pdf.get_y()
                group_gap = 12
                group_width = (78 * 2) + group_gap
                group_x = pdf.l_margin + ((pdf.w - pdf.l_margin - pdf.r_margin - group_width) / 2)
                if has_mig:
                    pdf.image(mig_path, x=group_x, y=y, w=78, h=chart_h)
                if has_lt:
                    x_pos = (group_x + 78 + group_gap) if has_mig else group_x
                    pdf.image(lt_path, x=x_pos, y=y, w=78, h=chart_h)
                pdf.set_y(y + chart_h + 4)

            if show_mig:
                mig_rows = [
                    [mig_labels[i] if i < len(mig_labels) else "",
                     fmt_num(mig_data[i] if i < len(mig_data) else 0, 0)]
                    for i in range(max(len(mig_labels), len(mig_data)))
                ]
                write_table_block(pdf, "Numerical Values: Migration Distribution", ["Category", "Count"], [130, 60], mig_rows, numeric_cols=(1,))
            if show_lt:
                lt_rows = [
                    [lt_labels[i] if i < len(lt_labels) else "",
                     fmt_num(lt_data[i] if i < len(lt_data) else 0, 0)]
                    for i in range(max(len(lt_labels), len(lt_data)))
                ]
                write_table_block(pdf, "Numerical Values: Light Tolerance Distribution", ["Category", "Count"], [130, 60], lt_rows, numeric_cols=(1,))

        scatter_specs: List[Tuple[str, str, str, str, str]] = [
            ("light_richness", "ALAN vs Richness", "ALAN (nW/cm2/sr)", "#dc2626", "alan_richness"),
            ("ndvi_richness", "NDVI vs Richness", "NDVI (index)", "#16a34a", "ndvi_richness"),
            ("lst_richness", "LST vs Richness", "LST (degC)", "#d97706", "lst_richness"),
            ("precipitation_richness", "Precipitation vs Richness", "Precipitation (mm)", "#0891b2", "precipitation_richness"),
        ]
        first_scatter = True
        for key, title, xlabel, color, sec_key in scatter_specs:
            if not section_enabled(selected_sections, sec_key):
                continue
            if first_scatter and not (show_mig or show_lt):
                # If no doughnut charts were shown, add the sub-section heading here
                pdf.ensure_space(10)
                pdf.set_font("Helvetica", "B", 10)
                pdf.set_x(lm)
                pdf.cell(text_w, 5, sanitize_for_pdf("Immediate Site Assessment & Vulnerability"), 0, 1, "L")
                pdf.filter_note(text_w, lm, f"Area: {area_str}  |  Snapshot range: {snap_range_str}")
            first_scatter = False

            block = safe_dict(snapshot_scatter.get(key))
            points = [p for p in safe_list(block.get("points")) if isinstance(p, dict)]
            path = make_scatter_chart(points, title, xlabel, color, os.path.join(tmp_dir, f"scatter_{key}.png"))
            place_chart(pdf, path, title, w=176, h=78)

            rows = []
            for p in points:
                rows.append([
                    p.get("site_name") or p.get("area") or "-",
                    p.get("area") or "-",
                    fmt_num(p.get("x"), 4),
                    fmt_num(p.get("y"), 2),
                ])
            write_table_block(
                pdf, f"Numerical Values: {title}",
                ["Site", "Area", "X", "Richness"], [62, 54, 34, 40], rows, numeric_cols=(2, 3),
            )

        if section_enabled(selected_sections, "top_sites"):
            top_rows = [r for r in safe_list(top_sites.get("rows")) if isinstance(r, dict)]
            top_chart = make_top_sites_chart(top_rows, os.path.join(tmp_dir, "top_sites.png"))
            place_chart(pdf, top_chart, "Top 10 Sites by Richness", w=180, h=72)

            top_table_rows = []
            for r in top_rows:
                top_table_rows.append([
                    r.get("rank", ""),
                    r.get("area", ""),
                    fmt_num(r.get("bird_richness"), 0),
                ])
            write_table_block(
                pdf, "Numerical Values: Top Sites by Richness",
                ["Rank", "Area", "Richness"], [24, 126, 40], top_table_rows, numeric_cols=(0, 2),
            )

        # ── Model Diagnostics ─────────────────────────────────────────────
        if has_diagnostic or has_systems:
            pdf.add_page()
            pdf.section_title("Model Diagnostics View")

        if section_enabled(selected_sections, "ensemble_metrics"):
            metrics_rows = [
                ["RMSE", fmt_num(ensemble.get("rmse"), 4)],
                ["MAE", fmt_num(ensemble.get("mae"), 4)],
                ["R2", fmt_num(ensemble.get("r2"), 4)],
            ]
            write_table_block(pdf, "KPI Cards", ["Metric", "Value"], [120, 70], metrics_rows, numeric_cols=(1,))

        filters_order = ["all", "light_sensitive", "light_tolerant", "migratory", "resident"]
        filter_title = {
            "all": "All Species", "light_sensitive": "Light Sensitive",
            "light_tolerant": "Light Tolerant", "migratory": "Migratory", "resident": "Resident",
        }

        if section_enabled(selected_sections, "xgboost_importance"):
            for f in filters_order:
                block = safe_dict(xgboost.get(f))
                title = f"XGBoost Feature Importance ({filter_title[f]})"
                chart = make_xgboost_chart(block, title, os.path.join(tmp_dir, f"xgboost_{f}.png"))
                place_chart(pdf, chart, title, w=176, h=74)
                labels = safe_list(block.get("labels"))
                values = safe_list(block.get("values"))
                n_imp = max(len(labels), len(values))
                imp_rows = [
                    [labels[i] if i < len(labels) else "",
                     fmt_num(values[i] if i < len(values) else None, 6)]
                    for i in range(n_imp)
                ]
                write_table_block(
                    pdf, "Numerical Values: " + title,
                    ["Feature", "Importance"], [130, 60], imp_rows, numeric_cols=(1,),
                )

        if section_enabled(selected_sections, "convlstm_predictions"):
            for f in filters_order:
                block = safe_dict(convlstm.get(f))
                title = f"ConvLSTM Actual vs Predicted ({filter_title[f]})"
                chart = make_convlstm_chart(block, title, os.path.join(tmp_dir, f"convlstm_{f}.png"))
                place_chart(pdf, chart, title, w=176, h=74)
                years = safe_list(block.get("years"))
                actual = safe_list(block.get("actual"))
                pred = safe_list(block.get("predicted"))
                n_pred = max(len(years), len(actual), len(pred))
                pred_rows = []
                for i in range(n_pred):
                    pred_rows.append([
                        years[i] if i < len(years) else "",
                        fmt_num(actual[i], 4) if i < len(actual) else "",
                        fmt_num(pred[i], 4) if i < len(pred) else "",
                    ])
                write_table_block(
                    pdf, "Numerical Values: " + title,
                    ["Year", "Actual", "Predicted"], [44, 73, 73], pred_rows, numeric_cols=(0, 1, 2),
                )

        # ── Conservation Priorities & Recommendations ─────────────────────
        if section_enabled(selected_sections, "kba_pa_audit") and kba_rows:
            pdf.ensure_space(12)
            pdf.set_font("Helvetica", "B", 10)
            pdf.set_x(lm)
            pdf.cell(text_w, 5, sanitize_for_pdf("Conservation Priorities & Recommendations"), 0, 1, "L")
            pdf.filter_note(text_w, lm, f"Area: {area_str}  |  Snapshot range: {snap_range_str}")

            core_rows: List[List[Any]] = []
            env_rows: List[List[Any]] = []
            for row in kba_rows:
                snap_year = row.get("snapshot_year")
                snap_month = row.get("snapshot_month")
                snap = "-"
                if snap_year not in (None, ""):
                    if snap_month not in (None, ""):
                        snap = f"{snap_year}-{str(snap_month).zfill(2)}"
                    else:
                        snap = str(snap_year)
                core_rows.append([
                    row.get("name", ""), row.get("type", ""),
                    fmt_num(row.get("species_count"), 0), fmt_num(row.get("sensitive_species_count"), 0),
                    fmt_num(row.get("sensitive_species_percent"), 1), fmt_num(row.get("light_exposure"), 1),
                    fmt_num(row.get("effectiveness_score"), 1), row.get("status", ""),
                ])
                env_rows.append([
                    row.get("name", ""),
                    fmt_num(row.get("mean_ndvi"), 4), fmt_num(row.get("max_lst"), 2),
                    fmt_num(row.get("precipitation_total"), 2), fmt_num(row.get("grid_cell_count"), 0), snap,
                ])

            write_table_block(
                pdf,
                "Key Biodiversity Area & Protected Areas Audit Table (Core)",
                ["Site", "Type", "Species", "Sens.Cnt", "Sens.%", "Light", "Eff.", "Status"],
                [55, 13, 16, 18, 17, 16, 13, 42],
                core_rows, numeric_cols=(2, 3, 4, 5, 6),
            )
            write_table_block(
                pdf,
                "Key Biodiversity Area & Protected Areas Audit Table (Environment)",
                ["Site", "Mean NDVI", "Max LST", "Precip", "Cells", "Snapshot"],
                [72, 24, 22, 24, 16, 32],
                env_rows, numeric_cols=(1, 2, 3, 4),
            )

        if section_enabled(selected_sections, "systems_recommendations"):
            recs = [r for r in safe_list(payload.get("systemsRecommendations")) if isinstance(r, dict)]
            if recs:
                indent_w = text_w - 6
                if not (section_enabled(selected_sections, "kba_pa_audit") and kba_rows):
                    # heading not yet written if kba was skipped
                    pdf.ensure_space(12)
                    pdf.set_font("Helvetica", "B", 10)
                    pdf.set_x(lm)
                    pdf.cell(text_w, 5, sanitize_for_pdf("Conservation Priorities & Recommendations"), 0, 1, "L")
                    pdf.filter_note(text_w, lm, f"Area: {area_str}  |  Snapshot range: {snap_range_str}")

                pdf.ensure_space(14)
                pdf.set_font("Helvetica", "B", 10)
                pdf.set_x(lm)
                pdf.cell(text_w, 6, sanitize_for_pdf("Rule-Based Recommendations"), 0, 1, "L")
                for rec in recs:
                    pdf.ensure_space(22)
                    pdf.set_font("Helvetica", "B", 9)
                    pdf.set_x(lm)
                    pdf.cell(text_w, 5, sanitize_for_pdf(rec.get("title", "")), 0, 1, "L")
                    if rec.get("source"):
                        pdf.set_font("Helvetica", "I", 8)
                        pdf.set_text_color(90, 90, 90)
                        pdf.set_x(lm)
                        pdf.cell(text_w, 4, sanitize_for_pdf(f"Source: {rec['source']}"), 0, 1, "L")
                        pdf.set_text_color(0, 0, 0)
                    pdf.set_font("Helvetica", "", 8)
                    pdf.set_x(lm)
                    pdf.multi_cell(text_w, 4, sanitize_for_pdf(rec.get("message", "")))
                    evidence = safe_list(rec.get("evidence"))
                    if evidence:
                        pdf.set_font("Helvetica", "B", 8)
                        pdf.set_x(lm)
                        pdf.cell(text_w, 4, sanitize_for_pdf("Evidence:"), 0, 1, "L")
                        pdf.set_font("Helvetica", "", 8)
                        for item in evidence:
                            pdf.ensure_space(6)
                            pdf.set_x(lm + 6)
                            pdf.multi_cell(indent_w, 4, sanitize_for_pdf(f"- {item}"))
                    pdf.ln(3)

    pdf.output(output_path)


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate AviLight report file.")
    parser.add_argument("--format", choices=["pdf", "csv"], required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    try:
        payload = read_payload()
        out_dir = os.path.dirname(os.path.abspath(args.output))
        if out_dir and not os.path.isdir(out_dir):
            os.makedirs(out_dir, exist_ok=True)

        if args.format == "pdf":
            write_pdf(payload, args.output)
            mime = "application/pdf"
        else:
            write_csv(payload, args.output)
            mime = "text/csv"

        print(json.dumps({"success": True, "format": args.format, "output": args.output, "mime": mime}))
        return 0
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
