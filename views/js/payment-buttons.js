(function () {
    'use strict';
    function init() {
        var dataElement = document.getElementById('payop-editor-data');
        if (!dataElement || dataElement.dataset.initialized) return;
        dataElement.dataset.initialized = '1';
        var data = JSON.parse(dataElement.value);
        var editor = document.getElementById('payop-buttons-editor');
        var form = document.getElementById('payop-settings-form');
        var rows = [];
        var fieldCounter = 0;
        function element(tag, text, parent) {
            var el = document.createElement(tag);
            if (text) el.textContent = text;
            if (parent) parent.appendChild(el);
            return el;
        }
        function field(parent, label, tag) {
            var wrapper = element('div', '', parent);
            wrapper.className = 'form-group';
            var caption = element('label', label, wrapper);
            var control = element(tag || 'input', '', wrapper);
            control.id = 'payop-button-field-' + (++fieldCounter);
            caption.htmlFor = control.id;
            return control;
        }
        function add(button) {
            var row = element('fieldset', '', editor);
            row.className = 'panel payop-payment-button';
            var enabledLabel = element('label', '', row);
            var enabled = element('input', '', enabledLabel);
            enabled.type = 'checkbox';
            enabled.checked = !!button.enabled;
            enabledLabel.appendChild(document.createTextNode(' Enabled'));
            var type = field(row, 'Integration type', 'select');
            [['hosted', 'Hosted Page'], ['payment_method', 'Hosted Page with Payment Method ID']].forEach(function (pair) {
                var option = element('option', pair[1], type);
                option.value = pair[0];
            });
            type.value = button.type;
            var method = field(row, 'Payment Method ID', 'select');
            var empty = element('option', 'Select a payment method', method);
            empty.value = '';
            data.methods.forEach(function (item) {
                var option = element('option', item.title + ' (' + item.identifier + ')', method);
                option.value = item.identifier;
            });
            if (button.payment_method && !data.methods.some(function (item) { return item.identifier === button.payment_method; })) {
                var saved = element('option', 'Saved method (' + button.payment_method + ')', method);
                saved.value = button.payment_method;
            }
            method.value = button.payment_method || '';
            var names = {}, descriptions = {};
            data.languages.forEach(function (language) {
                var id = language.id_lang;
                names[id] = field(row, 'Customer title — ' + language.name);
                names[id].type = 'text';
                names[id].maxLength = 255;
                names[id].required = Number(id) === Number(data.defaultLanguage);
                names[id].value = (button.names || {})[id] || '';
                descriptions[id] = field(row, 'Customer description — ' + language.name, 'textarea');
                descriptions[id].maxLength = 2000;
                descriptions[id].value = (button.descriptions || {})[id] || '';
            });
            function updateType() {
                var requires = type.value === 'payment_method';
                method.parentElement.hidden = !requires;
                method.disabled = !requires;
                method.required = requires;
            }
            type.addEventListener('change', updateType);
            updateType();
            var remove = element('button', 'Remove payment button', row);
            remove.type = 'button';
            remove.className = 'btn btn-danger';
            var state = { id: button.id, row: row, enabled: enabled, type: type, method: method, names: names, descriptions: descriptions };
            rows.push(state);
            remove.addEventListener('click', function () {
                rows = rows.filter(function (item) { return item !== state; });
                row.remove();
            });
        }
        data.buttons.forEach(add);
        document.getElementById('payop-add-button').addEventListener('click', function () {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            var id = 'button_' + Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
            add({ id: id, enabled: true, type: 'hosted', payment_method: '', names: {}, descriptions: {} });
        });
        form.addEventListener('submit', function () {
            document.getElementById('payop-buttons-json').value = JSON.stringify(rows.map(function (state) {
                var button = { id: state.id, enabled: state.enabled.checked, type: state.type.value, payment_method: state.type.value === 'payment_method' ? state.method.value : '', names: {}, descriptions: {} };
                Object.keys(state.names).forEach(function (id) {
                    button.names[id] = state.names[id].value;
                    button.descriptions[id] = state.descriptions[id].value;
                });
                return button;
            }));
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
