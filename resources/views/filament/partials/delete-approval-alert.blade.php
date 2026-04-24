<script>
    (() => {
        if (window.__deleteApprovalAlertRegistered) {
            return;
        }

        window.__deleteApprovalAlertRegistered = true;

        const storageKey = 'delete-approval-alert-seen-ids';
        const endpoint = @json(route('filament.admin.delete-approval-alerts'));
        const approveUrlTemplate = @json(route('filament.admin.delete-approval-alerts.approve', ['deletionRequest' => '__ID__']));
        const rejectUrlTemplate = @json(route('filament.admin.delete-approval-alerts.reject', ['deletionRequest' => '__ID__']));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const readSeenIds = () => {
            try {
                return JSON.parse(window.localStorage.getItem(storageKey) || '[]');
            } catch (_) {
                return [];
            }
        };

        const writeSeenIds = (ids) => {
            window.localStorage.setItem(storageKey, JSON.stringify(ids.slice(-100)));
        };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const removeExistingModal = () => {
            document.getElementById('delete-approval-alert-overlay')?.remove();
        };

        const buildItemLabel = (request) => {
            const label = request.title || request.handle || `Request #${request.id}`;
            const type = request.entity_type ? request.entity_type.charAt(0).toUpperCase() + request.entity_type.slice(1) : 'Record';
            return `${type}: ${label}`;
        };

        const approveUrlFor = (requestId) => approveUrlTemplate.replace('__ID__', String(requestId));
        const rejectUrlFor = (requestId) => rejectUrlTemplate.replace('__ID__', String(requestId));

        const markSeen = (requestIds) => {
            const seenIds = readSeenIds();
            const merged = [...new Set([...seenIds, ...requestIds])];
            writeSeenIds(merged);
        };

        const showToast = (message, success = true) => {
            const toast = document.createElement('div');
            toast.style.cssText = `position:fixed;right:24px;bottom:24px;z-index:10000;padding:12px 16px;border-radius:12px;color:#fff;font-size:14px;font-weight:600;box-shadow:0 10px 30px rgba(15,23,42,.2);background:${success ? '#16a34a' : '#dc2626'};`;
            toast.textContent = message;
            document.body.appendChild(toast);
            window.setTimeout(() => toast.remove(), 3000);
        };

        const showModal = (requests) => {
            removeExistingModal();

            const overlay = document.createElement('div');
            overlay.id = 'delete-approval-alert-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:24px;';

            const items = requests.map((request) => {
                const reason = request.reason ? `<div style="margin-top:4px;color:#475569;">Reason: ${escapeHtml(request.reason)}</div>` : '';
                return `
                    <li data-request-id="${request.id}" style="padding:10px 0;border-bottom:1px solid #e2e8f0;">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:600;color:#0f172a;">${escapeHtml(buildItemLabel(request))}</div>
                                <div style="margin-top:4px;color:#1e3a8a;">Requested by ${escapeHtml(request.requested_by)}.</div>
                                ${reason}
                            </div>
                            <div style="flex-shrink:0;display:flex;align-items:center;">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <button type="button" data-action="approve" data-request-id="${request.id}" style="border:0;border-radius:10px;background:#16a34a;color:#fff;padding:9px 14px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;">
                                        Approve
                                    </button>
                                    <button type="button" data-action="reject" data-request-id="${request.id}" style="border:0;border-radius:10px;background:#dc2626;color:#fff;padding:9px 14px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;">
                                        Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    </li>
                `;
            }).join('');

            overlay.innerHTML = `
                <div style="width:min(760px,100%);max-height:80vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 25px 60px rgba(15,23,42,.28);padding:24px 24px 18px;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                        <div>
                            <div style="font-size:20px;font-weight:700;color:#9a3412;">Delete approval required</div>
                            <div style="margin-top:8px;font-size:14px;line-height:20px;color:#334155;">
                                Another user requested deletion of the following products. Approve here to continue with the delete workflow, or ignore to review later.
                            </div>
                        </div>
                        <button type="button" id="delete-approval-alert-close" style="border:0;background:transparent;color:#64748b;font-size:24px;line-height:1;cursor:pointer;">&times;</button>
                    </div>
                    <ul style="list-style:none;margin:20px 0 0;padding:0;">
                        ${items}
                    </ul>
                    <div style="margin-top:18px;display:flex;justify-content:flex-end;">
                        <button type="button" id="delete-approval-alert-ok" style="border:0;border-radius:10px;background:#64748b;color:#fff;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer;">
                            Ignore
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            const dismiss = () => {
                markSeen(requests.map((request) => request.id));
                removeExistingModal();
            };

            const approveRequest = async (requestId) => {
                const approveButton = overlay.querySelector(`[data-action="approve"][data-request-id="${requestId}"]`);
                const rejectButton = overlay.querySelector(`[data-action="reject"][data-request-id="${requestId}"]`);
                if (approveButton) {
                    approveButton.disabled = true;
                    approveButton.textContent = 'Approving...';
                }
                if (rejectButton) {
                    rejectButton.disabled = true;
                }

                try {
                    const response = await fetch(approveUrlFor(requestId), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({}),
                    });

                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw new Error(payload.message || 'Delete approval failed.');
                    }

                    markSeen([requestId]);
                    overlay.querySelector(`[data-request-id="${requestId}"]`)?.remove();
                    showToast(payload.message || 'Delete approval recorded.');

                    if (!overlay.querySelector('li[data-request-id]')) {
                        removeExistingModal();
                    }
                } catch (error) {
                    if (approveButton) {
                        approveButton.disabled = false;
                        approveButton.textContent = 'Approve';
                    }
                    if (rejectButton) {
                        rejectButton.disabled = false;
                    }
                    showToast(error.message || 'Delete approval failed.', false);
                }
            };

            const rejectRequest = async (requestId) => {
                const approveButton = overlay.querySelector(`[data-action="approve"][data-request-id="${requestId}"]`);
                const rejectButton = overlay.querySelector(`[data-action="reject"][data-request-id="${requestId}"]`);
                const reason = window.prompt('Optional rejection reason:', '') ?? '';

                if (rejectButton) {
                    rejectButton.disabled = true;
                    rejectButton.textContent = 'Rejecting...';
                }
                if (approveButton) {
                    approveButton.disabled = true;
                }

                try {
                    const response = await fetch(rejectUrlFor(requestId), {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ reason }),
                    });

                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        throw new Error(payload.message || 'Delete rejection failed.');
                    }

                    markSeen([requestId]);
                    overlay.querySelector(`[data-request-id="${requestId}"]`)?.remove();
                    showToast(payload.message || 'Delete request rejected.');

                    if (!overlay.querySelector('li[data-request-id]')) {
                        removeExistingModal();
                    }
                } catch (error) {
                    if (rejectButton) {
                        rejectButton.disabled = false;
                        rejectButton.textContent = 'Reject';
                    }
                    if (approveButton) {
                        approveButton.disabled = false;
                    }
                    showToast(error.message || 'Delete rejection failed.', false);
                }
            };

            overlay.querySelector('#delete-approval-alert-close')?.addEventListener('click', dismiss);
            overlay.querySelector('#delete-approval-alert-ok')?.addEventListener('click', dismiss);
            overlay.querySelectorAll('[data-action="approve"]').forEach((button) => {
                button.addEventListener('click', () => approveRequest(button.getAttribute('data-request-id')));
            });
            overlay.querySelectorAll('[data-action="reject"]').forEach((button) => {
                button.addEventListener('click', () => rejectRequest(button.getAttribute('data-request-id')));
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
                // Ignore polling failures and try again on the next interval.
            }
        };

        poll();
        window.setInterval(poll, 10000);
    })();
</script>
