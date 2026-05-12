<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Refresh Reports Cache</title>
	<style>
		:root {
			color-scheme: dark;
			--bg: #07111f;
			--panel: #0f1b2d;
			--panel-2: #12233a;
			--text: #e5eefc;
			--muted: #8ea2c0;
			--border: rgba(148, 163, 184, 0.2);
			--accent: #dc2626;
			--good: #16a34a;
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			background:
				radial-gradient(circle at top left, rgba(59, 130, 246, 0.16), transparent 35%),
				radial-gradient(circle at bottom right, rgba(220, 38, 38, 0.12), transparent 32%),
				var(--bg);
			color: var(--text);
			display: grid;
			place-items: center;
			padding: 24px;
		}
		.shell {
			width: min(760px, 100%);
			background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
			border: 1px solid var(--border);
			border-radius: 20px;
			box-shadow: 0 24px 80px rgba(0, 0, 0, 0.28);
			overflow: hidden;
		}
		.hero {
			padding: 28px 28px 20px;
			background: linear-gradient(135deg, rgba(220,38,38,0.18), rgba(59,130,246,0.08));
			border-bottom: 1px solid var(--border);
		}
		.eyebrow {
			text-transform: uppercase;
			letter-spacing: 0.12em;
			font-size: 0.72rem;
			color: var(--muted);
			margin-bottom: 10px;
		}
		h1 { margin: 0; font-size: clamp(1.5rem, 3vw, 2.2rem); }
		p { margin: 0; }
		.body {
			padding: 24px 28px 28px;
			display: grid;
			gap: 16px;
		}
		.panel {
			background: rgba(15, 27, 45, 0.78);
			border: 1px solid var(--border);
			border-radius: 16px;
			padding: 18px;
		}
		.status {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 14px 16px;
			border-radius: 14px;
			background: rgba(255,255,255,0.02);
			border: 1px solid var(--border);
		}
		.spinner {
			width: 18px;
			height: 18px;
			border-radius: 50%;
			border: 2px solid rgba(148, 163, 184, 0.35);
			border-top-color: var(--accent);
			animation: spin 0.9s linear infinite;
			flex: 0 0 auto;
		}
		@keyframes spin { to { transform: rotate(360deg); } }
		.grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 12px;
		}
		.metric {
			background: var(--panel-2);
			border: 1px solid var(--border);
			border-radius: 14px;
			padding: 14px;
		}
		.metric .label { color: var(--muted); font-size: 0.82rem; margin-bottom: 6px; }
		.metric .value { font-size: 1.15rem; font-weight: 700; }
		pre {
			margin: 0;
			white-space: pre-wrap;
			word-break: break-word;
			background: #09131f;
			border: 1px solid var(--border);
			border-radius: 14px;
			padding: 14px;
			color: #dbeafe;
		}
		.actions {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}
		a.button, button.button {
			appearance: none;
			border: 0;
			border-radius: 999px;
			padding: 11px 16px;
			font: inherit;
			font-weight: 700;
			text-decoration: none;
			cursor: pointer;
		}
		a.button.primary, button.button.primary { background: var(--accent); color: white; }
		a.button.secondary, button.button.secondary { background: rgba(148,163,184,0.16); color: var(--text); }
		.success { color: #86efac; }
		.error { color: #fca5a5; }
		@media (max-width: 640px) {
			.shell, .hero, .body { border-radius: 0; }
			body { padding: 0; }
			.grid { grid-template-columns: 1fr; }
		}
	</style>
</head>
<body>
	<main class="shell">
		<section class="hero">
			<div class="eyebrow">Web refresh runner</div>
			<h1>Refresh Reports Cache</h1>
			<p style="margin-top:10px;color:var(--muted);">This page runs the same refresh that rebuilds <strong>observation_city_map</strong>, <strong>city_grid_map</strong>, and purges cached report files.</p>
		</section>

		<section class="body">
			<div class="status" id="statusBox">
				<div class="spinner" id="spinner"></div>
				<div>
					<div style="font-weight:700;" id="statusTitle">Starting refresh…</div>
					<div style="color:var(--muted);font-size:0.92rem;" id="statusDetail">Waiting for the server response.</div>
				</div>
			</div>

			<div class="panel" id="summaryPanel" style="display:none;">
				<div class="grid" id="metricsGrid"></div>
			</div>

			<div class="panel" id="messagePanel" style="display:none;">
				<div style="font-weight:700;margin-bottom:10px;">Result</div>
				<pre id="messageText"></pre>
			</div>

			<div class="actions">
				<button class="button primary" id="retryBtn" type="button" style="display:none;">Run Again</button>
				<a class="button secondary" href="admin.php">Back to Admin</a>
				<a class="button secondary" href="home.php">Home</a>
			</div>
		</section>
	</main>

	<script>
	const statusTitle = document.getElementById('statusTitle');
	const statusDetail = document.getElementById('statusDetail');
	const spinner = document.getElementById('spinner');
	const summaryPanel = document.getElementById('summaryPanel');
	const metricsGrid = document.getElementById('metricsGrid');
	const messagePanel = document.getElementById('messagePanel');
	const messageText = document.getElementById('messageText');
	const retryBtn = document.getElementById('retryBtn');

	function formatNumber(value) {
		const num = Number(value || 0);
		return Number.isFinite(num) ? num.toLocaleString() : '0';
	}

	function setBusy(isBusy) {
		spinner.style.display = isBusy ? 'block' : 'none';
		retryBtn.style.display = isBusy ? 'none' : 'inline-flex';
	}

	function renderMetrics(data) {
		const items = [];
		items.push({ label: 'Mapped observations', value: formatNumber(data?.spatial_maps?.mapped_obs_count) });
		items.push({ label: 'Mapped grid cells', value: formatNumber(data?.spatial_maps?.mapped_grid_count) });
		items.push({ label: 'Cache files purged', value: formatNumber(data?.cache_files_purged) });
		items.push({ label: 'Retries', value: formatNumber(data?.retry_attempts) });
		if (data?.summary_table && !data.summary_table.skipped) {
			items.push({ label: 'Summary rows', value: formatNumber(data.summary_table.total_rows) });
		}
		metricsGrid.innerHTML = items.map(item => `
			<div class="metric">
				<div class="label">${item.label}</div>
				<div class="value">${item.value}</div>
			</div>
		`).join('');
		summaryPanel.style.display = 'block';
	}

	async function runRefresh() {
		setBusy(true);
		statusTitle.textContent = 'Refreshing reports cache…';
		statusDetail.textContent = 'Rebuilding spatial mappings and clearing cached report files.';
		summaryPanel.style.display = 'none';
		messagePanel.style.display = 'none';

		try {
			const response = await fetch('api/refresh_report_cache.php?force=1', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ force: true })
			});
			const data = await response.json();

			setBusy(false);
			if (data && data.success) {
				statusTitle.textContent = 'Refresh complete';
				statusTitle.className = 'success';
				statusDetail.textContent = data.message || 'The cache was refreshed successfully.';
				renderMetrics(data);
				messagePanel.style.display = 'block';
				messageText.textContent = JSON.stringify(data, null, 2);
			} else {
				statusTitle.textContent = 'Refresh failed';
				statusTitle.className = 'error';
				statusDetail.textContent = (data && data.error) ? data.error : 'The server returned an error.';
				messagePanel.style.display = 'block';
				messageText.textContent = JSON.stringify(data || { error: 'Unknown error' }, null, 2);
			}
		} catch (err) {
			setBusy(false);
			statusTitle.textContent = 'Refresh failed';
			statusTitle.className = 'error';
			statusDetail.textContent = err.message || 'Network or server error.';
			messagePanel.style.display = 'block';
			messageText.textContent = err.stack || err.message || String(err);
		}
	}

	retryBtn.addEventListener('click', runRefresh);
	window.addEventListener('DOMContentLoaded', runRefresh);
	</script>
</body>
</html>