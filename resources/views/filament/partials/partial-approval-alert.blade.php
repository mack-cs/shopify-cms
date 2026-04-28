<script>
    (() => {
        if (window.__partialApprovalAlertRegistered) {
            return;
        }

        window.__partialApprovalAlertRegistered = true;

        const storageKey = 'partial-approval-alert-seen-ids';
        const endpoint = @json(route('filament.admin.partial-approval-alerts'));

        const readSeenIds = () => {
            try {
                return JSON.parse(window.localStorage.getItem(storageKey) || '[]');
            } catch (_) {
                return [];
            }
        };

        const writeSeenIds = (ids) => {
            window.localStorage.setItem(storageKey, JSON.stringify(ids.slice(-200)));
        };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const removeExistingModal = () => {
            document.getElementById('partial-approval-alert-overlay')?.remove();
        };

        const markSeen = (requestIds) => {
            const seenIds = readSeenIds();
            const merged = [...new Set([...seenIds, ...requestIds])];
            writeSeenIds(merged);
        };

        const showModal = (requests) => {
            removeExistingModal();

            const overlay = document.createElement('div');
            overlay.id = 'partial-approval-alert-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:24px;';

            const queueUrl = requests[0]?.queue_url || '#';

            const items = requests.slice(0, 5).map((request) => {
                const handle = request.handle ? `<div style="margin-top:4px;color:#475569;">Handle: ${escapeHtml(request.handle)}</div>` : '';
                const fields = Array.isArray(request.requested_fields) && request.requested_fields.length
                    ? `<div style="margin-top:6px;color:#334155;">Fields: ${escapeHtml(request.requested_fields.join(', '))}</div>`
                    : '';

                return `
                    <li style="padding:10px 0;border-bottom:1px solid #e2e8f0;">
                        <div style="font-weight:600;color:#0f172a;">${escapeHtml(request.title)}</div>
                        <div style="margin-top:4px;color:#1e3a8a;">Requested by ${escapeHtml(request.requested_by)}.</div>
                        ${handle}
                        ${fields}
                    </li>
                `;
            }).join('');

            const moreCount = Math.max(0, requests.length - 5);
            const moreNotice = moreCount > 0
                ? `<div style="margin-top:12px;color:#475569;font-size:14px;">${moreCount} more request(s) are waiting in the queue.</div>`
                : '';

            overlay.innerHTML = `
                <div style="width:min(760px,100%);max-height:80vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 25px 60px rgba(15,23,42,.28);padding:24px 24px 18px;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                        <div>
                            <div style="font-size:20px;font-weight:700;color:#9a3412;">Partial approvals waiting</div>
                            <div style="margin-top:8px;font-size:14px;line-height:20px;color:#334155;">
                                Another user requested partial approval. Review the queue to see the requested columns and approve the items that are ready.
                            </div>
                        </div>
                        <button type="button" id="partial-approval-alert-close" style="border:0;background:transparent;color:#64748b;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
                    </div>
                    <ul style="list-style:none;margin:20px 0 0;padding:0;">
                        ${items}
                    </ul>
                    ${moreNotice}
                    <div style="margin-top:18px;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" id="partial-approval-alert-ignore" style="border:0;border-radius:10px;background:#64748b;color:#fff;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;">
                            Ignore
                        </button>
                        <a href="${escapeHtml(queueUrl)}" id="partial-approval-alert-review" style="display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#d97706;color:#fff;padding:10px 16px;font-size:14px;font-weight:600;text-decoration:none;">
                            Review queue
                        </a>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            const dismiss = () => {
                markSeen(requests.map((request) => request.id));
                removeExistingModal();
            };

            overlay.querySelector('#partial-approval-alert-close')?.addEventListener('click', dismiss);
            overlay.querySelector('#partial-approval-alert-ignore')?.addEventListener('click', dismiss);
            overlay.querySelector('#partial-approval-alert-review')?.addEventListener('click', () => {
                markSeen(requests.map((request) => request.id));
            });
        };

        const poll = async () => {
            try {
                const response = await fetch(endpoint, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                const requests = Array.isArray(payload.requests) ? payload.requests : [];
                const seenIds = new Set(readSeenIds());
                const unseen = requests.filter((request) => !seenIds.has(request.id));

                if (unseen.length > 0) {
                    showModal(unseen);
                }
            } catch (_) {
                // Ignore polling failures and try again later.
            }
        };

        poll();
        window.setInterval(poll, 10000);
    })();
</script>
