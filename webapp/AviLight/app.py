import streamlit as st
import folium
from streamlit_folium import st_folium
import pandas as pd
import plotly.express as px

# Page config (no padding, no UI)

st.set_page_config(
    layout="wide",
)

#Hide Streamlit UI elements
st.markdown(
    """
    <style>
        .block-container {
            padding: 0.5rem 0.5rem 0.5rem 0.5rem;
            max-height: 260px;
        }
        iframe {
            max-height: 260px !important;
        }
    </style>
    """,
    unsafe_allow_html=True
)

#Layout: Map then Trends sa gilid
map_col, trend_col = st.columns([1.2,1])

#Dummy Map Data (nilagay ko sa manila para maangas)

zones = pd.DataFrame({
    "zone": ["A1", "A2", "A3", "A4", "A5"],
    "lat": [14.609, 14.585, 14.620, 14.570, 14.650],
    "lon": [120.980, 120.995, 121.010, 120.965, 121.030],
    "risk": ["Low", "Medium", "High", "Low", "Medium"]
})

risk_colors = {
    "Low": "green",
    "Medium": "orange",
    "High": "red"
}

#Map Visualization
with map_col:
    m = folium.Map(
        location=[14.5995, 120.9842],
        zoom_start=11,
        tiles="cartodbpositron"
    )

for _, row in zones.iterrows():
    folium.CircleMarker(
        location=[row.lat, row.lon],
        radius=20,
        color=risk_colors[row.risk],
        fill=True,
        fill_opacity=0.45,
        popup=f"""
        <b>Zone {row.zone}</b><br>
        Risk Level: {row.risk}
        """
    ).add_to(m)

st_folium(
    m,
    height=260,
    width=None
)


#Bird Richness
with trend_col:
    bird_data = pd.DataFrame({
        "Month": ["Jan", "Feb", "Mar", "Apr", "May", "Jun"],
        "Species Count": [120, 180, 150, 210, 190, 240]
    })

    fig = px.line(
        bird_data,
        x="Month",
        y="Species Count",
        markers=True,
        title="Bird Species Richness Trend"
    )

    fig.update_layout(
        height=360,
        margin=dict(l=10, r=10, t=40, b=10),
        hovermode="x unified"
    )

    st.plotly_chart(fig, use_container_width=True)
