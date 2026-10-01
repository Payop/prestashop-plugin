<?php

trait PayopPaymentOptions
{
    public function getAdditionalButtons()
    {
        $buttons = json_decode((string) Configuration::get('PAYOP_BUTTONS'), true);
        return is_array($buttons) ? $buttons : [];
    }

    public function validateButtons($json)
    {
        if (!is_string($json) || !is_array($buttons = json_decode($json, true)) || array_values($buttons) !== $buttons) {
            throw new InvalidArgumentException($this->l('Invalid payment buttons. Reload the page and try again.'));
        }
        $normalized = [];
        $ids = [];
        $languages = Language::getLanguages(false);
        $defaultLanguage = (int) Configuration::get('PS_LANG_DEFAULT');
        // A saved method remains editable when the API is unavailable. New IDs must
        // originate from the last successfully loaded catalogue for this project.
        $allowedMethods = [];
        $catalogue = $this->getCachedPaymentMethods();
        foreach ($catalogue as $method) {
            $allowedMethods[(string) $method['identifier']] = true;
        }
        $saved = [];
        foreach ($this->getAdditionalButtons() as $button) {
            $saved[$button['id']] = $button;
        }
        foreach ($buttons as $button) {
            if (!is_array($button)) {
                throw new InvalidArgumentException($this->l('Invalid payment button.'));
            }
            $id = isset($button['id']) && is_string($button['id']) ? $button['id'] : '';
            if (!preg_match('/^button_[a-f0-9]{16,64}$/', $id) || isset($ids[$id])) {
                throw new InvalidArgumentException($this->l('Invalid or duplicate payment button identifier.'));
            }
            $ids[$id] = true;
            $type = isset($button['type']) && is_string($button['type']) ? $button['type'] : '';
            if (!in_array($type, ['hosted', 'payment_method'], true)) {
                throw new InvalidArgumentException($this->l('Select a valid integration type.'));
            }
            $methodId = isset($button['payment_method']) && is_scalar($button['payment_method']) ? trim((string) $button['payment_method']) : '';
            if ($type === 'payment_method') {
                if (!preg_match('/^[1-9][0-9]*$/', $methodId) || strlen($methodId) > 64) {
                    throw new InvalidArgumentException($this->l('Select a Payment Method ID for every method-specific button.'));
                }
                $unchanged = isset($saved[$id]) && $saved[$id]['payment_method'] === $methodId;
                if (!isset($allowedMethods[$methodId]) && !$unchanged) {
                    throw new InvalidArgumentException($this->l('Refresh the project payment methods and select an available method.'));
                }
            } else {
                $methodId = '';
            }
            $entry = ['id' => $id, 'enabled' => !empty($button['enabled']), 'type' => $type, 'payment_method' => $methodId, 'names' => [], 'descriptions' => []];
            foreach ($languages as $language) {
                $langId = (int) $language['id_lang'];
                foreach (['names' => 255, 'descriptions' => 2000] as $field => $limit) {
                    $value = isset($button[$field][$langId]) ? $button[$field][$langId] : '';
                    if (!is_string($value) || Tools::strlen($value) > $limit || !Validate::isCleanHtml($value, false)) {
                        throw new InvalidArgumentException($this->l('Invalid payment button name or description.'));
                    }
                    $entry[$field][$langId] = trim(strip_tags($value));
                }
            }
            if (empty($entry['names'][$defaultLanguage])) {
                throw new InvalidArgumentException($this->l('Enter a button name in the default shop language.'));
            }
            $normalized[] = $entry;
        }
        return $normalized;
    }

    public function getCheckoutButtons()
    {
        $langId = (int) $this->context->language->id;
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $buttons = [[
            'id' => 'default', 'type' => 'hosted', 'payment_method' => '',
            'name' => (string) Configuration::get('PAYOP_NAME') ?: $this->displayName,
            'description' => (string) Configuration::get('DESCRIPTION'),
        ]];
        foreach ($this->getAdditionalButtons() as $button) {
            if (empty($button['enabled'])) {
                continue;
            }
            $button['name'] = !empty($button['names'][$langId]) ? $button['names'][$langId] : $button['names'][$defaultLangId];
            $button['description'] = !empty($button['descriptions'][$langId]) ? $button['descriptions'][$langId] : ($button['descriptions'][$defaultLangId] ?? '');
            $buttons[] = $button;
        }
        return $buttons;
    }

    public function resolvePaymentButton($id)
    {
        if (!is_string($id)) {
            return false;
        }
        foreach ($this->getCheckoutButtons() as $button) {
            if ($button['id'] === $id) {
                return $button;
            }
        }
        return false;
    }

    public function applyInvoicePaymentMethod(array $request, array $button)
    {
        unset($request['paymentMethod']);
        if ($button['type'] === 'payment_method') {
            $request['paymentMethod'] = $button['payment_method'];
        }
        return $request;
    }

    private function methodsProjectKey()
    {
        return hash('sha256', trim((string) Configuration::get('PAYOP_PUBLIC_KEY')));
    }

    public function getCachedPaymentMethods()
    {
        $cache = json_decode((string) Configuration::get('PAYOP_METHODS_CACHE'), true);
        return is_array($cache) && isset($cache['project'], $cache['methods']) && $cache['project'] === $this->methodsProjectKey() ? $cache['methods'] : [];
    }

    public function loadPaymentMethods($refresh = false)
    {
        $publicKey = trim((string) Configuration::get('PAYOP_PUBLIC_KEY'));
        $token = $this->getMethodsToken();
        $cached = $this->getCachedPaymentMethods();
        $cache = json_decode((string) Configuration::get('PAYOP_METHODS_CACHE'), true);
        $fingerprint = hash('sha256', $publicKey . '|' . $token);
        if (!$refresh && isset($cache['fingerprint'], $cache['expires']) && $cache['fingerprint'] === $fingerprint && $cache['expires'] > time()) {
            return ['ok' => true, 'methods' => $cached];
        }
        if ($publicKey === '' || $token === '') {
            return ['ok' => false, 'methods' => $cached, 'error' => $this->l('Save the Public Key and JWT Token to load methods. Ordinary Hosted Page does not require this token.')];
        }
        $applicationId = preg_replace('/^application-/', '', $publicKey);
        $response = $this->requestPaymentMethods($applicationId, $token);
        if (empty($response['ok'])) {
            return ['ok' => false, 'methods' => $cached, 'error' => $response['error']];
        }
        $methods = [];
        foreach ($response['data'] as $method) {
            if (!is_array($method) || !isset($method['identifier']) || !is_scalar($method['identifier'])) {
                continue;
            }
            $id = (string) $method['identifier'];
            if (!preg_match('/^[1-9][0-9]*$/', $id) || strlen($id) > 64) {
                continue;
            }
            $title = isset($method['title']) && is_scalar($method['title']) ? strip_tags((string) $method['title']) : $id;
            $methods[] = ['identifier' => $id, 'title' => $title];
        }
        Configuration::updateValue('PAYOP_METHODS_CACHE', json_encode(['project' => $this->methodsProjectKey(), 'fingerprint' => $fingerprint, 'expires' => time() + 300, 'methods' => $methods]));
        return ['ok' => true, 'methods' => $methods];
    }

    protected function requestPaymentMethods($applicationId, $token)
    {
        $ch = curl_init('https://api.payop.com/v1/instrument-settings/payment-methods/available-for-application/' . rawurlencode($applicationId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $response = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($response) || !isset($response['data']) || !is_array($response['data'])) {
            $message = in_array($status, [401, 403], true)
                ? $this->l('Unable to load payment methods: invalid JWT or insufficient project permissions. Previously saved buttons were retained.')
                : $this->l('Unable to load payment methods. Check the Public Key and JWT, then retry. Previously saved buttons were retained.');
            return ['ok' => false, 'error' => $message];
        }
        return ['ok' => true, 'data' => $response['data']];
    }
}
