(function () {
    'use strict';

    var STATUS_LABELS = {
        pending_payment: 'Pago pendiente',
        confirmed_paid: 'Confirmada',
        confirmed_external: 'Confirmada',
        reschedule_requested: 'Reagendamiento solicitado',
        rescheduled: 'Reagendada',
        refund_requested: 'Reembolso solicitado',
        refund_pending: 'Reembolso pendiente',
        refunded: 'Reembolsada',
        cancelled: 'Cancelada',
        expired: 'Expirada'
    };

    function request(path, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({
            'X-WP-Nonce': (window.wpApiSettings || {}).nonce || LBScheduler.nonce || ''
        }, options.headers || {});
        return fetch(LBScheduler.url + path, options).then(function (response) {
            return response.text().then(function (text) {
                var data = {};
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (error) {
                    data = {};
                }
                if (!response.ok) {
                    throw new Error(data.message || 'No se pudo completar la solicitud.');
                }
                return data;
            });
        });
    }

    function clear(container) {
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
    }

    function renderMessage(container, className, message) {
        clear(container);
        var messageElement = document.createElement('p');
        messageElement.className = className;
        messageElement.textContent = message;
        container.appendChild(messageElement);
    }

    function addError(container, message) {
        var previous = container.querySelector('[data-lb-error]');
        if (previous) {
            previous.remove();
        }
        var error = document.createElement('p');
        error.className = 'lb-scheduler__error';
        error.dataset.lbError = 'true';
        error.setAttribute('role', 'alert');
        error.textContent = message;
        container.insertBefore(error, container.firstChild);
    }

    function loginForSlot(slot) {
        var login = new URL(LBScheduler.loginUrl, window.location.href);
        var destination = new URL(window.location.href);
        destination.searchParams.set('lb_start', slot.start);
        destination.searchParams.set('lb_duration', String(slot.duration));
        login.searchParams.set('redirect_to', destination.toString());
        window.location.href = login.toString();
    }

    function pendingSelection() {
        var params = new URLSearchParams(window.location.search);
        return { start: params.get('lb_start') || '', duration: params.get('lb_duration') || '' };
    }

    function formatTime(value) {
        var parsed = new Date(value);
        if (isNaN(parsed.getTime())) {
            return value.slice(11, 16);
        }
        return new Intl.DateTimeFormat('es-MX', { hour: '2-digit', minute: '2-digit' }).format(parsed);
    }

    function formatSessionDate(value) {
        var parsed = new Date(value.replace(' ', 'T'));
        if (isNaN(parsed.getTime())) {
            return value;
        }
        return new Intl.DateTimeFormat('es-MX', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit'
        }).format(parsed);
    }

    function statusLabel(status) {
        return STATUS_LABELS[status] || status.replace(/_/g, ' ');
    }

    function actionButton(label, callback) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'lb-scheduler-session__action';
        button.textContent = label;
        button.addEventListener('click', callback);
        return button;
    }

    document.querySelectorAll('[data-lb-scheduler]').forEach(function (root) {
        var date = root.querySelector('[data-lb-date]');
        var phone = root.querySelector('[data-lb-phone]');
        var slots = root.querySelector('[data-lb-slots]');
        var selection = pendingSelection();

        function loadSlots() {
            if (!date.value) {
                slots.className = 'lb-scheduler__slots';
                slots.setAttribute('aria-busy', 'false');
                renderMessage(slots, 'lb-scheduler__empty', 'Selecciona una fecha para ver horarios.');
                return;
            }
            slots.className = 'lb-scheduler__slots lb-scheduler__slots--loading';
            slots.setAttribute('aria-busy', 'true');
            renderMessage(slots, 'lb-scheduler__loading', 'Buscando horarios disponibles…');
            request('slots?date=' + encodeURIComponent(date.value)).then(function (data) {
                clear(slots);
                slots.className = 'lb-scheduler__slots';
                slots.setAttribute('aria-busy', 'false');
                if (!data.slots || !data.slots.length) {
                    renderMessage(slots, 'lb-scheduler__empty', 'No hay horarios disponibles para esta fecha.');
                    return;
                }
                data.slots.forEach(function (slot) {
                    var button = document.createElement('button');
                    var selected = selection.start === slot.start && selection.duration === String(slot.duration);
                    button.type = 'button';
                    button.className = 'lb-scheduler__slot' + (selected ? ' is-selected' : '');
                    button.dataset.start = slot.start;
                    button.dataset.duration = String(slot.duration);
                    button.setAttribute('aria-pressed', selected ? 'true' : 'false');
                    button.setAttribute('aria-label', 'Reservar ' + formatTime(slot.start) + ', ' + slot.duration + ' minutos');

                    var time = document.createElement('span');
                    time.className = 'lb-scheduler__slot-time';
                    time.textContent = formatTime(slot.start);
                    var duration = document.createElement('span');
                    duration.className = 'lb-scheduler__slot-duration';
                    duration.textContent = slot.duration + ' minutos';
                    button.appendChild(time);
                    button.appendChild(duration);

                    button.addEventListener('click', function () {
                        if (!LBScheduler.loggedIn) {
                            loginForSlot(slot);
                            return;
                        }
                        button.disabled = true;
                        button.classList.add('is-loading');
                        request('bookings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ start: slot.start, duration: slot.duration, phone: phone ? phone.value : '' }) }).then(function (data) {
                            window.location.href = data.payment_url;
                        }).catch(function (error) {
                            button.disabled = false;
                            button.classList.remove('is-loading');
                            addError(slots, error.message);
                        });
                    });
                    slots.appendChild(button);
                });
            }).catch(function (error) {
                slots.className = 'lb-scheduler__slots';
                slots.setAttribute('aria-busy', 'false');
                renderMessage(slots, 'lb-scheduler__error', error.message);
            });
        }

        date.addEventListener('change', function () {
            selection = { start: '', duration: '' };
            loadSlots();
        });
        if (selection.start) {
            date.value = selection.start.slice(0, 10);
        }
        if (date.value) {
            loadSlots();
        }
    });

    document.querySelectorAll('[data-lb-sessions]').forEach(function (root) {
        var output = root.querySelector('[data-lb-session-list]');

        function loadSessions() {
            output.setAttribute('aria-busy', 'true');
            request('sessions').then(function (data) {
                clear(output);
                output.setAttribute('aria-busy', 'false');
                if (!data.sessions || !data.sessions.length) {
                    renderMessage(output, 'lb-scheduler__empty', 'Aún no tienes sesiones.');
                    return;
                }
                data.sessions.forEach(function (session) {
                    var item = document.createElement('article');
                    var details = document.createElement('div');
                    var date = document.createElement('strong');
                    var meta = document.createElement('span');
                    var status = document.createElement('span');
                    var actions = document.createElement('div');
                    item.className = 'lb-scheduler-session';
                    details.className = 'lb-scheduler-session__details';
                    date.className = 'lb-scheduler-session__date';
                    date.textContent = formatSessionDate(session.start_at);
                    meta.className = 'lb-scheduler-session__meta';
                    meta.textContent = 'Sesión · ' + session.duration + ' minutos';
                    status.className = 'lb-scheduler-session__status lb-scheduler-session__status--' + session.status;
                    status.textContent = statusLabel(session.status);
                    actions.className = 'lb-scheduler-session__actions';
                    details.appendChild(date);
                    details.appendChild(meta);
                    item.appendChild(details);
                    item.appendChild(status);

                    if (['confirmed_paid', 'confirmed_external', 'rescheduled', 'pending_payment'].indexOf(session.status) >= 0) {
                        actions.appendChild(actionButton('Cancelar', function () {
                            if (!window.confirm('¿Cancelar esta sesión? Las reservas pagadas pasan a revisión de reembolso.')) return;
                            request('bookings/' + encodeURIComponent(session.id) + '/cancel', { method: 'POST' }).then(loadSessions).catch(function (error) { addError(output, error.message); });
                        }));
                    }
                    if (['confirmed_paid', 'confirmed_external'].indexOf(session.status) >= 0) {
                        actions.appendChild(actionButton('Reagendar', function () {
                            var requested = window.prompt('Nueva fecha y hora (YYYY-MM-DDTHH:MM):', session.start_at.replace(' ', 'T').slice(0, 16));
                            if (!requested) return;
                            request('bookings/' + encodeURIComponent(session.id) + '/reschedule', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ start: requested }) }).then(loadSessions).catch(function (error) { addError(output, error.message); });
                        }));
                    }
                    if (['confirmed_paid', 'confirmed_external', 'rescheduled'].indexOf(session.status) >= 0) {
                        actions.appendChild(actionButton('Solicitar reembolso', function () {
                            if (!window.confirm('¿Solicitar revisión de reembolso para esta sesión?')) return;
                            request('bookings/' + encodeURIComponent(session.id) + '/refund', { method: 'POST' }).then(loadSessions).catch(function (error) { addError(output, error.message); });
                        }));
                    }
                    if (actions.firstChild) {
                        item.appendChild(actions);
                    }
                    output.appendChild(item);
                });
            }).catch(function (error) {
                output.setAttribute('aria-busy', 'false');
                renderMessage(output, 'lb-scheduler__error', error.message);
            });
        }

        loadSessions();
    });
}());
