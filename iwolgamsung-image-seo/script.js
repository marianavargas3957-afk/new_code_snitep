jQuery(document).ready(function($) {
	'use strict';

	let allData = [];
	const itemsPerPage = 10;
	let currentPage = 1;
	const typeLabels = {
		product: '상품',
		post: '글'
	};
	const stopWords = ['the', 'and', 'amazon', 'item', 'best', 'with'];

	function post(action, data) {
		// Use text transport + guarded JSON parsing so stray output does not break the full batch flow.
		const dfd = $.Deferred();
		$.ajax({
			url: imgSeoPro.ajaxUrl,
			method: 'POST',
			dataType: 'text',
			data: Object.assign({ action: action, nonce: imgSeoPro.nonce }, data || {})
		}).done(function(raw) {
			const rawText = (typeof raw === 'string' ? raw : String(raw || ''))
				.replace(/^\uFEFF/, '')
				.trim();

			try {
				dfd.resolve(JSON.parse(rawText));
			} catch (e) {
				// Fallback parser: recover JSON when non-JSON prefix/suffix is injected into the response.
				const firstBrace = rawText.indexOf('{');
				const lastBrace = rawText.lastIndexOf('}');
				if (firstBrace !== -1 && lastBrace > firstBrace) {
					const jsonSlice = rawText.slice(firstBrace, lastBrace + 1);
					try {
						dfd.resolve(JSON.parse(jsonSlice));
						return;
					} catch (_ignored) {
						// Keep flowing to structured failure below.
					}
				}

				dfd.resolve({
					success: false,
					data: 'JSON parse failed',
					raw: rawText.substring(0, 500)
				});
			}
		}).fail(function(xhr, statusText, errorThrown) {
			dfd.reject(xhr, statusText, errorThrown);
		});

		return dfd.promise();
	}

	function selectedPostTypes() {
		const types = [];
		$('.img-seo-post-type:checked').each(function() {
			types.push($(this).val());
		});
		return types.length ? types : ['product'];
	}

	function updateLiveServerStats() {
		post('img_seo_get_server_usage').done(function(res) {
			if (res.success) {
				$('#live_server_usage').text(`현재: CPU ${res.data.cpu}% | MEM ${res.data.mem}%`);
			} else {
				$('#live_server_usage').text('현재: 비활성');
			}
		});
	}

	updateLiveServerStats();
	setInterval(updateLiveServerStats, 3000);

	function getLiveDelay() {
		const dfd = $.Deferred();
		post('img_seo_get_live_delay').done(function(res) {
			if (res.success && res.data && res.data.delay) {
				dfd.resolve(res.data.delay);
			} else {
				dfd.resolve(parseInt($('#delay_time').val(), 10) || 5);
			}
		}).fail(function() {
			dfd.resolve(parseInt($('#delay_time').val(), 10) || 5);
		});
		return dfd.promise();
	}

	function makeSlug(title) {
		const words = title.toLowerCase().replace(/[^\w\sㄱ-ㅎㅏ-ㅣ가-힣]/gi, ' ').split(/\s+/).filter(word => word && !stopWords.includes(word));
		return words.slice(0, 4).join('-') || 'product-img';
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, char => ({
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		}[char]));
	}

	function renderPage(page) {
		currentPage = page;
		const start = (page - 1) * itemsPerPage;
		const pageData = allData.slice(start, start + itemsPerPage);
		const container = $('#product_container').empty();
		const grouped = pageData.reduce(function(acc, group) {
			const key = group.type || 'product';
			if (!acc[key]) {
				acc[key] = [];
			}
			acc[key].push(group);
			return acc;
		}, {});
		const order = ['product', 'post'];
		let html = '';

		order.forEach(function(typeKey) {
			const groups = grouped[typeKey] || [];
			if (!groups.length) {
				return;
			}

			const typeLabel = typeLabels[typeKey] || typeKey;
			html += `<section class="img-seo-type-group">
				<div class="img-seo-type-heading">${typeLabel}</div>`;

			groups.forEach(function(group) {
				let imgs = '';
				const base = makeSlug(group.title);
				const safeTitle = escapeHtml(group.title);
				const groupLabel = group.type_label || typeLabel;
				const saveLabel = group.type === 'post' ? '이 글만 저장' : '이 상품만 저장';

				group.images.forEach(function(url, index) {
					const slug = `${base}-${index + 1}`;
					imgs += `<div class="img-row ${index === 0 ? 'is-featured' : ''}" id="row-${group.id}-${index}">
						<input type="radio" name="feat_${group.id}" value="${url}" ${index === 0 ? 'checked' : ''}>
						<img src="${url}" alt="">
						<div class="img-seo-alt-wrap"><input type="text" class="alt-in" data-id="${group.id}" data-url="${url}" data-slug="${slug}" value="${String(group.title).substring(0, 60).replace(/,/g, '')}"></div>
						<span class="img-seo-feature-tag">대표</span>
						<span id="st-${group.id}-${index}">-</span>
					</div>`;
				});

				html += `<div class="prod-card" id="card-${group.id}">
					<div class="prod-head">
						<strong title="${safeTitle}">${groupLabel} #${group.id} ${safeTitle}</strong>
						<span class="img-seo-count">수량 ${group.count || group.images.length}개</span>
					</div>
					<div class="prod-actions">
						<button class="save-btn single-save" data-pid="${group.id}">${saveLabel}</button>
						<a href="${group.link}" target="_blank" class="view-link">미리보기</a>
					</div>
					<div class="img-seo-image-group">${imgs}</div>
				</div>`;
			});

			html += '</section>';
		});

		container.html(html);

		renderPaginationButtons();
	}

	function syncFeaturedRow(pid) {
		const card = $(`#card-${pid}`);
		card.find('.img-row').removeClass('is-featured');
		card.find('input[type="radio"]:checked').closest('.img-row').addClass('is-featured');
	}

	function renderPaginationButtons() {
		const totalPages = Math.ceil(allData.length / itemsPerPage);
		let html = '';
		for (let i = 1; i <= totalPages; i++) {
			html += `<span class="page-num ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</span>`;
		}
		$('.pagination').html(html);
	}

	$(document).on('click', '.page-num', function() {
		renderPage(parseInt($(this).data('page'), 10));
		window.scrollTo(0, 0);
	});

	$('#save_delay_setting').click(function() {
		const btn = $(this);
		btn.prop('disabled', true).text('저장 중...');

		post('img_seo_save_settings', {
			delay_time: parseInt($('#delay_time').val(), 10) || 5,
			keyword: $('#search_keyword').val(),
			adaptive_status: $('#adaptive_status').val(),
			cpu_threshold: parseInt($('#cpu_threshold').val(), 10) || 70,
			mem_threshold: parseInt($('#mem_threshold').val(), 10) || 70,
			post_types: selectedPostTypes(),
			cron_enabled: $('#cron_enabled').is(':checked') ? 'on' : 'off'
		}).done(function(res) {
			$('#log_area').append(res.success ? '> 설정 저장 완료<br>' : '> 설정 저장 실패<br>').scrollTop(9999);
		}).always(function() {
			btn.prop('disabled', false).text('설정 저장');
		});
	});

	$('#scan_images').click(function() {
		const btn = $(this);
		btn.prop('disabled', true).text('스캔 중...');
		$('#log_area').html('&gt; [딥스캔] 스캔 중...<br>');
		// Keep the client-side default aligned with the UI default value.
		const deepScanLimit = Math.max(parseInt($('#limit_count').val(), 10) || 100, 1);

		post('img_seo_scan_v1200', {
			keyword: $('#search_keyword').val(),
			// Respect the user-entered search limit instead of forcing 1000 on every scan.
			limit: deepScanLimit,
			post_types: selectedPostTypes()
		}).done(function(res) {
			const payload = res && res.data ? res.data : {};
			const items = Array.isArray(payload) ? payload : (Array.isArray(payload.items) ? payload.items : []);
			const meta = !Array.isArray(payload) && payload.meta ? payload.meta : {};

			if (!res || !res.success) {
				const errorText = res && res.data ? (typeof res.data === 'string' ? res.data : JSON.stringify(res.data)) : '알 수 없는 응답 오류';
				const rawHint = res && res.raw ? ` ${escapeHtml(res.raw)}` : '';
				$('#log_area').append(`> [딥스캔] 요청 실패: ${escapeHtml(errorText)}${rawHint}<br>`);
				return;
			}

			if (items.length > 0) {
				allData = items;
				renderPage(1);
				$('#start_batch_all').show().text(`검색된 ${allData.length}개 항목 전체 실행`);
				if (meta.candidate_count && meta.candidate_count !== allData.length) {
					$('#log_area').append(`> [딥스캔] 후보 ${meta.candidate_count}건 중 ${allData.length}건이 정규식 일치.<br>`);
				}
				$('#log_area').append(`> [딥스캔] 총 ${allData.length}건 발견. 하단 리스트에서 확인 가능.<br>`);
				allData.forEach(group => {
					const typeLabel = group.type_label || typeLabels[group.type] || group.type;
					$('#log_area').append(`> [딥스캔] ${typeLabel} #${group.id} 이미지 ${group.count || group.images.length}개 감지<br>`);
				});
			} else {
				if (meta.candidate_count) {
					$('#log_area').append(`> [딥스캔] 후보 ${meta.candidate_count}건이 있었지만 정규식 일치가 없습니다.<br>`);
				}
				$('#log_area').append('> [딥스캔] 검색 결과가 없습니다.<br>');
			}
		}).fail(function(xhr, statusText) {
			const rawText = xhr && xhr.responseText ? xhr.responseText.substring(0, 200) : '';
			$('#log_area').append(`> [딥스캔] 통신 실패: ${escapeHtml(statusText || 'error')} ${escapeHtml(rawText)}<br>`);
		}).always(function() {
			btn.prop('disabled', false).text('전체스캔');
		});
	});

	$('#scan_broken_images').click(function() {
		const btn = $(this);
		btn.prop('disabled', true).text('점검 중...');
		$('#log_area').html('> [점검] 썸네일/첨부 연결/외부 이미지 사용 여부를 점검합니다...<br>').scrollTop(9999);
		// Keep the broken-image default aligned with the UI default value.
		const brokenScanLimit = Math.max(parseInt($('#limit_count').val(), 10) || 100, 1);

		post('img_seo_scan_broken_v1200', {
			keyword: $('#search_keyword').val(),
			// Keep the broken-image scan aligned with the user-entered limit.
			limit: brokenScanLimit,
			post_types: selectedPostTypes()
		}).done(function(res) {
			if (res.success && res.data.length > 0) {
				allData = res.data;
				renderPage(1);
				$('#start_batch_all').show().text(`발견된 ${allData.length}개 점검 대상 전체 실행`);
				$('#log_area').append(`<span style="color:#d63638;">> [점검 결과] 썸네일/첨부 연결/외부 이미지 기준 점검 대상 ${allData.length}건을 찾았습니다.</span><br>`).scrollTop(9999);
				allData.forEach(group => {
					const typeLabel = group.type_label || typeLabels[group.type] || group.type;
					$('#log_area').append(`> [점검] ${typeLabel} #${group.id} 이미지 ${group.count || group.images.length}개 감지<br>`);
				});
			} else {
				$('#product_container').empty();
				$('.pagination').empty();
				$('#start_batch_all').hide();
				$('#log_area').append('> [정상] 점검 기준에 걸린 항목이 없습니다.<br>').scrollTop(9999);
			}
		}).always(function() {
			btn.prop('disabled', false).text('외부 이미지/썸네일 점검');
		});
	});

	$(document).on('click', '.single-save', function() {
		processProduct($(this).data('pid'));
	});

	$(document).on('change', '.img-row input[type="radio"]', function() {
		const pid = $(this).closest('.prod-card').attr('id').replace('card-', '');
		syncFeaturedRow(pid);
	});

	// 실시간으로 ALT 입력 필드에서 쉼표 제거
	$(document).on('input', '.alt-in', function() {
		this.value = this.value.replace(/,/g, '');
	});

	$('#start_batch_all').click(async function() {
		const total = allData.length;
		$(this).prop('disabled', true).text('전체 작업 진행 중...');
		$('#progress_bar_container').show();

		for (let i = 0; i < allData.length; i++) {
			const product = allData[i];
			const typeLabel = product.type_label || typeLabels[product.type] || product.type;
			const percent = Math.round(((i + 1) / total) * 100);
			$('#progress_bar').css('width', percent + '%');
			$('#log_area').append(`[${i + 1}/${total}] ${typeLabel} #${product.id} 처리 시작...<br>`).scrollTop(9999);
			$(`#card-${product.id}`).addClass('processing');

			const liveDelay = await getLiveDelay();
			$('#delay_time').val(liveDelay);

			try {
				const result = await processProduct(product.id);
				$(`#card-${product.id}`).removeClass('processing').css('background', '#f0fff0');
				syncFeaturedRow(product.id);

				if (result && result.status === 'skipped') {
					$('#log_area').append(`<span style="color:#9a7b00;">> ${typeLabel} #${product.id} 이미 처리되어 건너뜀.</span><br>`).scrollTop(9999);
				} else if (result && result.status === 'final_fail') {
					$('#log_area').append(`<span style="color:#d63638;">> ${typeLabel} #${product.id} 최종 저장 실패.</span><br>`).scrollTop(9999);
				} else {
					$('#log_area').append(`<span style="color:#ffb700;">> ${typeLabel} #${product.id} 처리 완료.</span><br>`).scrollTop(9999);
				}
			} catch (err) {
				$(`#card-${product.id}`).removeClass('processing').addClass('process-error');
				const errText = err && err.message ? err.message : String(err || 'Unknown error');
				$('#log_area').append(`<span style="color:#d63638;">> ${typeLabel} #${product.id} 처리 실패: ${escapeHtml(errText)}</span><br>`).scrollTop(9999);
				continue;
			}
		}

		$(this).prop('disabled', false).text(`검색된 ${total}개 항목 전체 실행`);

		if ($('#cron_enabled').is(':checked')) {
			try {
				const cronRes = await post('img_seo_run_cron_now');
				if (cronRes && cronRes.success) {
					$('#log_area').append('> [WP-Cron] 전체스캔 완료 후 자동 실행 완료<br>').scrollTop(9999);
				} else {
					$('#log_area').append('> [WP-Cron] 자동 실행 실패<br>').scrollTop(9999);
				}
			} catch (e) {
				$('#log_area').append('> [WP-Cron] 자동 실행 중 오류 발생<br>').scrollTop(9999);
			}
		}
	});

	$('#run_cron_now').click(async function() {
		const btn = $(this);
		btn.prop('disabled', true).text('실행 중...');
		try {
			const res = await post('img_seo_run_cron_now');
			$('#log_area').append(res && res.success ? '> [WP-Cron] 즉시 실행 완료<br>' : '> [WP-Cron] 즉시 실행 실패<br>').scrollTop(9999);
		} catch (e) {
			$('#log_area').append('> [WP-Cron] 즉시 실행 중 오류 발생<br>').scrollTop(9999);
		} finally {
			btn.prop('disabled', false).text('즉시 실행');
		}
	});

	// ---- Background batch (survives browser close) ----
	let bgPollInterval = null;

	$('#start_bg_batch').click(async function() {
		const btn = $(this);
		if (btn.prop('disabled')) return;

		// Check if we're resuming a paused batch
		const progressRes = await post('img_seo_batch_progress');
		const isPaused = progressRes && progressRes.success && progressRes.data && progressRes.data.status === 'paused';

		if (isPaused) {
			// Resume: just schedule the next tick
			btn.prop('disabled', true).text('재개 중...');
			try {
				const res = await post('img_seo_run_background_batch', {
					keyword: $('#search_keyword').val(),
					post_types: selectedPostTypes()
				});
				if (res && res.success) {
					$('#log_area').append('> [백그라운드] 배치 재개됨<br>').scrollTop(9999);
					$('#safe_stop_batch').show();
					startBgPolling();
				} else {
					$('#log_area').append('> [백그라운드] 재개 실패<br>').scrollTop(9999);
					btn.prop('disabled', false).text('백그라운드 재개');
				}
			} catch (e) {
				$('#log_area').append(`> [백그라운드] 재개 오류: ${escapeHtml(String(e))}<br>`).scrollTop(9999);
				btn.prop('disabled', false).text('백그라운드 재개');
			}
			return;
		}

		btn.prop('disabled', true).text('배치 시작 중...');
		$('#safe_stop_batch').hide();
		$('#log_area').append('> [백그라운드] 스캔 및 배치 큐 초기화 중...<br>').scrollTop(9999);

		try {
			const res = await post('img_seo_run_background_batch', {
				keyword: $('#search_keyword').val(),
				post_types: selectedPostTypes()
			});

			if (res && res.success && res.data) {
				$('#log_area').append(`> [백그라운드] ${res.data.message}<br>`).scrollTop(9999);
				$('#bg_progress_bar_container').show();
				updateBgProgressBar(0, res.data.count);
				$('#safe_stop_batch').show();
				startBgPolling();
			} else {
				const errMsg = res && res.data ? (typeof res.data === 'string' ? res.data : JSON.stringify(res.data)) : '시작 실패';
				$('#log_area').append(`> [백그라운드] 오류: ${escapeHtml(errMsg)}<br>`).scrollTop(9999);
				btn.prop('disabled', false).text('백그라운드 전체 실행');
			}
		} catch (e) {
			$('#log_area').append(`> [백그라운드] 요청 실패: ${escapeHtml(String(e))}<br>`).scrollTop(9999);
			btn.prop('disabled', false).text('백그라운드 전체 실행');
		}
	});

	$('#safe_stop_batch').click(async function() {
		const btn = $(this);
		btn.prop('disabled', true).text('중지 요청 중...');

		try {
			const res = await post('img_seo_batch_safe_stop');
			if (res && res.success) {
				$('#log_area').append(`> [백그라운드] ${res.data ? res.data.message : '안전 중지 요청됨'}<br>`).scrollTop(9999);
			} else {
				$('#log_area').append('> [백그라운드] 중지 요청 실패<br>').scrollTop(9999);
			}
		} catch (e) {
			$('#log_area').append(`> [백그라운드] 중지 요청 오류: ${escapeHtml(String(e))}<br>`).scrollTop(9999);
		}

		btn.prop('disabled', false).text('안전 중지');
	});

	function startBgPolling() {
		if (bgPollInterval) clearInterval(bgPollInterval);

		bgPollInterval = setInterval(async function() {
			try {
				const res = await post('img_seo_batch_progress');
				if (res && res.success && res.data) {
					const p = res.data;
					updateBgProgressBar(p.current, p.total);

					// Show last 3 log entries
					if (p.log && p.log.length > 0) {
						const recentLogs = p.log.slice(-3);
						recentLogs.forEach(function(msg) {
							$('#log_area').append(`> [백그라운드] ${escapeHtml(msg)}<br>`);
						});
						// Clear shown logs to avoid re-append
						p.log.splice(0, p.log.length - 3);
					}

					$('#log_area').scrollTop(9999);

					if (p.status === 'paused') {
						// Safe stop complete: stop polling, show resume opportunity
						clearInterval(bgPollInterval);
						bgPollInterval = null;
						$('#safe_stop_batch').hide();
						$('#start_bg_batch').prop('disabled', false).text('백그라운드 재개');
						$('#log_area').append('> [백그라운드] 안전 중지 완료. "백그라운드 재개" 버튼으로 이어서 실행 가능.<br>').scrollTop(9999);
					} else if (p.status === 'done' || p.status === 'idle') {
						clearInterval(bgPollInterval);
						bgPollInterval = null;
						$('#safe_stop_batch').hide();
						$('#start_bg_batch').prop('disabled', false).text('백그라운드 전체 실행');
						if (p.status === 'done') {
							$('#log_area').append('> [백그라운드] 전체 배치 완료!<br>').scrollTop(9999);
						}
					}
				}
			} catch (e) {
				// Silently retry on next interval
			}
		}, 3000);
	}

	function updateBgProgressBar(current, total) {
		const pct = total > 0 ? Math.round((current / total) * 100) : 0;
		$('#bg_progress_bar').css('width', pct + '%').text(`${current}/${total} (${pct}%)`);
	}

	// Check for existing batch on page load
	$(function() {
		(async function checkExistingBatch() {
			try {
				const res = await post('img_seo_batch_progress');
				if (res && res.success && res.data) {
					const p = res.data;
					if (p.status === 'running') {
						$('#log_area').append('> [백그라운드] 진행 중인 배치 발견, 모니터링 재개...<br>').scrollTop(9999);
						$('#bg_progress_bar_container').show();
						$('#safe_stop_batch').show();
						updateBgProgressBar(p.current, p.total);
						startBgPolling();
					} else if (p.status === 'paused') {
						$('#log_area').append('> [백그라운드] 일시 중지된 배치 발견. "백그라운드 재개"로 이어서 실행 가능.<br>').scrollTop(9999);
						$('#bg_progress_bar_container').show();
						updateBgProgressBar(p.current, p.total);
						$('#start_bg_batch').prop('disabled', false).text('백그라운드 재개');
					}
				}
			} catch (e) {
				// No existing batch
			}
		})();
	});

	async function processProduct(pid) {
		const product = allData.find(item => item.id == pid);
		if (!product) {
			return { status: 'missing' };
		}

		const featUrl = $(`input[name="feat_${pid}"]:checked`).val() || product.images[0];
		const map = {};
		const alts = {};
		let savedCount = 0;
		let skippedCount = 0;

		for (let i = 0; i < product.images.length; i++) {
			const url = product.images[i];
			const element = $(`.alt-in[data-id="${pid}"][data-url="${url}"]`);
			const alt = element.length ? element.val().replace(/,/g, '') : String(product.title).replace(/,/g, '');
			const slug = element.length ? element.attr('data-slug') : makeSlug(product.title) + '-' + (i + 1);
			alts[url] = alt;

			try {
				const res = await post('img_seo_save_v1200', {
					post_id: pid,
					img_url: url,
					alt: alt,
					slug: slug,
					is_feat: (url === featUrl ? 1 : 0)
				});
				if (res && res.success && res.data && res.data.skipped) {
					$(`#st-${pid}-${i}`).html('SKIP');
					skippedCount++;
				} else if (res.success) {
					$(`#st-${pid}-${i}`).html('OK');
					map[url] = res.data.new_url;
					savedCount++;
				} else {
					$(`#st-${pid}-${i}`).html('FAIL');
				}
			} catch(e) {
				$(`#st-${pid}-${i}`).html('FAIL');
			}
		}

		if (savedCount === 0 && skippedCount > 0) {
			$('#log_area').append(`> [#${pid}] 이미 처리된 항목이라 저장을 건너뜀<br>`).scrollTop(9999);
			return { status: 'skipped' };
		}

		if (savedCount === 0 && Object.keys(map).length === 0) {
			return { status: 'empty' };
		}

		try {
			const finalRes = await post('img_seo_final_v1200', { post_id: pid, map: map, alts: alts });
			if (finalRes && finalRes.success) {
				$('#log_area').append(`> [#${pid}] 로컬 저장 및 DB 연결 완료<br>`).scrollTop(9999);
				return { status: 'done', savedCount, skippedCount };
			}
			const finalMsg = finalRes && finalRes.data ? (typeof finalRes.data === 'string' ? finalRes.data : JSON.stringify(finalRes.data)) : 'Final save failed';
			$('#log_area').append(`> [#${pid}] 최종 저장 실패: ${escapeHtml(finalMsg)}<br>`).scrollTop(9999);
			return { status: 'final_fail', message: finalMsg, savedCount, skippedCount };
		} catch (e) {
			const finalMsg = e && e.message ? e.message : String(e || 'Final save failed');
			$('#log_area').append(`> [#${pid}] 최종 저장 예외: ${escapeHtml(finalMsg)}<br>`).scrollTop(9999);
			return { status: 'final_fail', message: finalMsg, savedCount, skippedCount };
		}
	}
});
