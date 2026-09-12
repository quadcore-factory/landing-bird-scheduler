(function () {
    'use strict';

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
                    throw new Error(data.message || 'Request failed');
                }
                return data;
            });
        });
    }

    function addError(container, message) {
        var error = document.createElement('p');
        error.className = 'lb-scheduler__error';
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

    document.querySelectorAll('[data-lb-scheduler]').forEach(function (root) {
        var date = root.querySelector('[data-lb-date]');
        var slots = root.querySelector('[data-lb-slots]');
        var selection = pendingSelection();

        function loadSlots() {
            if (!date.value) {
                slots.textContent = 'Selecciona una fecha.';
                return;
            }
            slots.textContent = 'Cargando…';
            request('slots?date=' + encodeURIComponent(date.value)).then(function (data) {
                while (slots.firstChild) slots.removeChild(slots.firstChild);
                if (!data.slots || !data.slots.length) {
                    slots.textContent = 'No hay horarios disponibles para esta fecha.';
                    return;
                }
                data.slots.forEach(function (slot) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = slot.start.slice(11, 16) + ' · ' + slot.duration + ' min';
                    button.dataset.start = slot.start;
                    button.dataset.duration = String(slot.duration);
                    if (selection.start === slot.start && selection.duration === String(slot.duration)) {
                        button.className = 'is-selected';
                    }
                    button.addEventListener('click', function () {
                        if (!LBScheduler.loggedIn) {
                            loginForSlot(slot);
                            return;
                        }
                        request('bookings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ start: slot.start, duration: slot.duration }) }).then(function (data) {
                            window.location.href = data.payment_url;
                        }).catch(function (error) {
                            addError(slots, error.message);
                        });
                    });
                    slots.appendChild(button);
                });
            }).catch(function (error) {
                slots.textContent = error.message;
            });
        }

        date.addEventListener('change', loadSlots);
        if (selection.start) date.value = selection.start.slice(0, 10);
        if (date.value) loadSlots();
    });

    document.querySelectorAll('[data-lb-sessions]').forEach(function (root) {
        var output = root.querySelector('[data-lb-session-list]');

        function loadSessions() {
            request('sessions').then(function (data) {
                while (output.firstChild) output.removeChild(output.firstChild);
                if (!data.sessions || !data.sessions.length) {
                    output.textContent = 'Aún no tienes sesiones.';
                    return;
                }
                data.sessions.forEach(function (session) {
                    var item = document.createElement('p');
                    item.textContent = session.start_at + ' · ' + session.duration + ' min · ' + session.status + ' ';
                    if (['confirmed_paid', 'confirmed_external', 'rescheduled', 'pending_payment'].indexOf(session.status) >= 0) {
                        var cancel = document.createElement('button');
                        cancel.type = 'button';
                        cancel.textContent = 'Cancelar';
                        cancel.addEventListener('click', function () {
                            if (!window.confirm('¿Cancelar esta sesión? Las reservas pagadas pasan a revisión de reembolso.')) return;
                            request('bookings/' + encodeURIComponent(session.id) + '/cancel', { method: 'POST' }).then(loadSessions).catch(function (error) { addError(output, error.message); });
                        });
                        item.appendChild(cancel);
                    }
                    if (['confirmed_paid', 'confirmed_external'].indexOf(session.status) >= 0) {
                        var reschedule = document.createElement('button');
                        reschedule.type = 'button';
                        reschedule.textContent = 'Reagendar';
                        reschedule.addEventListener('click', function () {
                            var requested = window.prompt('Nueva fecha y hora (YYYY-MM-DDTHH:MM):', session.start_at.replace(' ', 'T').slice(0, 16));
                            if (!requested) return;
                            request('bookings/' + encodeURIComponent(session.id) + '/reschedule', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ start: requested }) }).then(loadSessions).catch(function (error) { addError(output, error.message); });
                        });
                        item.appendChild(reschedule);
                    }
                    if (['confirmed_paid', 'confirmed_external', 'rescheduled'].indexOf(session.status) >= 0) {
                        var refund = document.createElement('button');
                        refund.type = 'button';
                        refund.textContent = 'Solicitar reembolso';
                        refund.addEventListener('click', function () {
                            if (!window.confirm('¿Solicitar revisión de reembolso para esta sesión?')) return;
                            request('bookings/' + encodeURIComponent(session.id) + '/refund', { method: 'POST' }).then(loadSessions).catch(function (error) { addError(output, error.message); });
                        });
                        item.appendChild(refund);
                    }
                    output.appendChild(item);
                });
            }).catch(function (error) {
                output.textContent = error.message;
            });
        }

        loadSessions();
    });
}());
