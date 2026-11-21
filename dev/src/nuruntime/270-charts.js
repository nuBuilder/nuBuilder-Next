	// =================================================================== charts (Google Charts) – nuBuilder's nuChart() and chart objects
	/*
	 * nuChart(chartId, chartType, dataArray, chartTitle, xAxisTitle, yAxisTitle, seriesType, isStacked [, options]) – as nuBuilder.
	 * dataArray: name of a global array ('myData', 'obj.data'), an array literal as text, an array, a function returning one, or a
	 * google.visualization.DataTable. Chart objects (type chart) call it by themselves when the form is shown.
	 * Also: the Google loader is loaded once and only when needed; colours follow light / dark mode (redrawn on a switch); the
	 * chart is redrawn when its box changes size (also when a hidden tab is opened); a message instead of a blank box when there
	 * is no data or Google Charts cannot be loaded; options (9th parameter) are merged into the chart options; returns a Promise
	 * with the ChartWrapper; window[chartId + '_wrapper'] and nuChartOnReady(chartId, wrapper) as nuBuilder.
	 */
	var gcPromise = null, chartEls = [];
	function loadGoogleCharts() {
		if (gcPromise) return gcPromise;
		gcPromise = new Promise(function (resolve, reject) {
			var start = function () {
				try {
					google.charts.load('current', {packages: ['corechart'], language: document.documentElement.lang || 'en'});
					google.charts.setOnLoadCallback(function () { resolve(window.google); });
				} catch (e) { reject(e); }
			};
			if (window.google && google.charts && google.charts.load) { start(); return; }
			var s = document.createElement('script');
			s.src = 'https://www.gstatic.com/charts/loader.js';
			s.onload = start;
			s.onerror = function () { reject(new Error('loader')); };
			document.head.appendChild(s);
			setTimeout(function () { reject(new Error('timeout')); }, 20000);
		});
		gcPromise.catch(function () { gcPromise = null; });   // a later call may try again
		return gcPromise;
	}
	function chartData(d) {
		if (typeof d === 'function') d = d();
		if (typeof d === 'string') {
			var s = d.trim();
			if (s === '') return null;
			if (/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/.test(s)) {           // a (global) variable name, as nuBuilder's eval(name)
				d = s.split('.').reduce(function (o, k) { return o == null ? o : o[k]; }, window);
				if (d === undefined) { try { d = (0, eval)('(' + s + ')'); } catch (x) { d = undefined; } }   // eslint-disable-line no-eval -- let / const in the form code
			} else {
				d = (0, eval)('(' + s + ')');                                          // eslint-disable-line no-eval
			}
			if (typeof d === 'function') d = d();
		}
		return d;
	}
	function chartMessage(el, text, kind) {
		var m = el.querySelector('.nuChartMsg');
		if (!m) { m = document.createElement('div'); m.className = 'nuChartMsg'; el.appendChild(m); }
		m.className = 'nuChartMsg' + (kind ? ' nuChartMsg-' + kind : '');
		m.textContent = text;
		m.hidden = false;
	}
	function chartColours() {
		var cs = getComputedStyle(document.body);
		var fg = cs.color, muted = cs.getPropertyValue('--bs-secondary-color').trim() || fg, grid = cs.getPropertyValue('--bs-border-color').trim() || '#dee2e6';
		var ts = {color: fg}, ms = {color: muted};
		return {backgroundColor: 'transparent', fontName: cs.fontFamily.split(',')[0].replace(/["']/g, '').trim(), titleTextStyle: ts, legend: {textStyle: ts},
			hAxis: {textStyle: ms, titleTextStyle: ms, gridlines: {color: grid}, baselineColor: grid},
			vAxis: {textStyle: ms, titleTextStyle: ms, gridlines: {color: grid}, baselineColor: grid},
			pieSliceBorderColor: cs.backgroundColor || 'transparent'};
	}
	api('nuChart', function (chartId, chartType, dataArray, chartTitle, xAxisTitle, yAxisTitle, seriesType, isStacked, options) {
		var el = typeof chartId === 'string' ? g(chartId) : chartId;
		if (!el) return Promise.resolve(null);
		var id = el.id;
		var data;
		try { data = chartData(dataArray); } catch (e) { reportError(e.message, 'nuChart ' + id); data = null; }
		var isTable = data && typeof data.getNumberOfRows === 'function';
		if (!isTable && (!Array.isArray(data) || data.length < 2)) {
			chartMessage(el, (M.chart_no_data || 'No data for the chart') + (typeof dataArray === 'string' && dataArray.trim() ? ': ' + dataArray.trim() : ''), 'muted');
			return Promise.resolve(null);
		}
		return loadGoogleCharts().then(function () {
			var base = {title: chartTitle || '', hAxis: {title: xAxisTitle || ''}, vAxis: {title: yAxisTitle || ''}, isStacked: !!isStacked && isStacked !== 'false'};
			if (seriesType) base.seriesType = seriesType;
			var opts = $.extend(true, chartColours(), base, options || {});
			var table = isTable ? data : google.visualization.arrayToDataTable(data);
			var st = el._nuChart;
			if (st && st.ro) st.ro.disconnect();
			el.textContent = '';
			var wrapper = new google.visualization.ChartWrapper({chartType: chartType || 'ColumnChart', dataTable: table, containerId: id, options: opts});
			var first = true;
			google.visualization.events.addListener(wrapper, 'ready', function () {
				if (first && typeof window.nuChartOnReady === 'function') { first = false; try { window.nuChartOnReady(id, wrapper); } catch (x) { reportError(x.message, 'nuChartOnReady'); } }
			});
			google.visualization.events.addListener(wrapper, 'error', function (e) { reportError(e.message, 'nuChart ' + id); });
			if (chartEls.indexOf(el) < 0) chartEls.push(el);
			st = el._nuChart = {wrapper: wrapper, base: $.extend(true, {}, base, options || {}), w: 0, h: 0, timer: null};
			var draw = function () {
				var r = el.getBoundingClientRect();
				if (!r.width || !r.height) return;                       // hidden tab: drawn when it is shown
				if (Math.abs(r.width - st.w) < 2 && Math.abs(r.height - st.h) < 2) return;
				st.w = r.width; st.h = r.height;
				wrapper.draw();
			};
			if (window.ResizeObserver) {
				st.ro = new ResizeObserver(function () { clearTimeout(st.timer); st.timer = setTimeout(draw, 120); });
				st.ro.observe(el);
			}
			draw();
			window[id + '_wrapper'] = wrapper;
			return wrapper;
		}, function () {
			chartMessage(el, M.chart_load_failed || 'Google Charts could not be loaded (www.gstatic.com).', 'error');
			return null;
		});
	});
	// light / dark switch: redraw with the new colours
	if (window.MutationObserver) new MutationObserver(function () {
		chartEls = chartEls.filter(function (el) { return el.isConnected; });
		chartEls.forEach(function (el) {
			var st = el._nuChart;
			if (!st || !st.wrapper) return;
			st.wrapper.setOptions($.extend(true, chartColours(), st.base));
			st.w = 0; st.h = 0;
			if (el.getBoundingClientRect().width) { st.w = el.getBoundingClientRect().width; st.h = el.getBoundingClientRect().height; st.wrapper.draw(); }
		});
	}).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});
	function initCharts(root) {
		$(root || document).find('.nuChart').each(function () {
			if (this._nuChart || this.closest('template')) return;
			var d = this.dataset;
			if (!d.nuChartType) { chartMessage(this, M.chart_no_type || 'Choose a chart type in the builder.', 'muted'); return; }
			nuChart(this.id, d.nuChartType, d.nuChartData || '', d.nuChartTitle, d.nuChartHtitle, d.nuChartVtitle, d.nuChartSeries, d.nuChartStacked === '1');
		});
	}
	N.initCharts = initCharts;

