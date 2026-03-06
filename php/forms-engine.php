<?php
/**
 * Outpost CMS — Forms Engine
 * Renders builder-defined forms as HTML from the `forms` table.
 *
 * Usage in templates:
 *   {% form 'contact' %}
 *
 * Compiles to:
 *   <?php cms_form('contact'); ?>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Render a builder form by slug.
 */
function cms_form(string $slug): void {
    $form = OutpostDB::fetchOne('SELECT * FROM forms WHERE slug = ? AND status = ?', [$slug, 'active']);
    if (!$form) {
        echo "<!-- Outpost: form '{$slug}' not found -->";
        return;
    }

    $fields   = json_decode($form['fields'], true) ?: [];
    $settings = json_decode($form['settings'], true) ?: [];

    $submitLabel = $settings['submit_label'] ?? 'Submit';
    $honeypot    = $settings['honeypot'] ?? true;
    $cssClass    = 'outpost-form outpost-form--' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
    $formAction  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/outpost/form.php';

    // Use a relative path if we're already inside /outpost/
    if (defined('OUTPOST_DIR')) {
        $formAction = str_replace('//', '/', '/' . trim(str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', OUTPOST_DIR), '/') . '/form.php');
    }

    echo '<form class="' . $cssClass . '" action="' . htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') . '" method="post">' . "\n";
    echo '  <input type="hidden" name="_form" value="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '  <input type="hidden" name="_form_id" value="' . (int)$form['id'] . '">' . "\n";

    // Confirmation redirect
    if (!empty($settings['confirmation_type']) && $settings['confirmation_type'] === 'redirect' && !empty($settings['redirect_url'])) {
        echo '  <input type="hidden" name="_redirect" value="' . htmlspecialchars($settings['redirect_url'], ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    foreach ($fields as $field) {
        cms_form_render_field($field);
    }

    // Honeypot (hidden via CSS)
    if ($honeypot) {
        echo '  <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">' . "\n";
        echo '    <input type="text" name="_hp_' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" tabindex="-1" autocomplete="off">' . "\n";
        echo '  </div>' . "\n";
    }

    echo '  <button type="submit" class="outpost-form-submit">' . htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') . '</button>' . "\n";
    echo '</form>' . "\n";
}

/**
 * Render a single form field.
 */
function cms_form_render_field(array $field): void {
    $type        = $field['type'] ?? 'text';
    $name        = $field['name'] ?? '';
    $label       = $field['label'] ?? '';
    $placeholder = $field['placeholder'] ?? '';
    $description = $field['description'] ?? '';
    $required    = !empty($field['required']);
    $cssClasses  = $field['css_classes'] ?? '';
    $defaultVal  = $field['default_value'] ?? '';
    $choices     = $field['choices'] ?? [];
    $settings    = $field['settings'] ?? [];

    // URL pre-population
    $value = $_GET[$name] ?? $defaultVal;

    $fieldId   = 'outpost-f-' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $wrapClass = 'outpost-field outpost-field--' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    if ($cssClasses) $wrapClass .= ' ' . htmlspecialchars($cssClasses, ENT_QUOTES, 'UTF-8');

    // Non-input types
    if ($type === 'html') {
        require_once __DIR__ . '/sanitizer.php';
        $safeContent = OutpostSanitizer::clean($settings['content'] ?? '');
        echo '  <div class="' . $wrapClass . '">' . $safeContent . '</div>' . "\n";
        return;
    }

    if ($type === 'section') {
        echo '  <div class="' . $wrapClass . '">' . "\n";
        if ($label) echo '    <h3 class="outpost-section-title">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</h3>' . "\n";
        if ($description) echo '    <p class="outpost-section-desc">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
        echo '  </div>' . "\n";
        return;
    }

    if ($type === 'hidden') {
        echo '  <input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        return;
    }

    echo '  <div class="' . $wrapClass . '" data-field="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    // Label
    if ($label && !in_array($type, ['hidden'])) {
        echo '    <label for="' . $fieldId . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($required) echo ' <span class="outpost-required">*</span>';
        echo '</label>' . "\n";
    }

    $reqAttr = $required ? ' required' : '';
    $phAttr  = $placeholder ? ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"' : '';
    $valAttr = ' value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
    $nameAttr = ' name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"';
    $idAttr  = ' id="' . $fieldId . '"';

    switch ($type) {
        case 'text':
            echo '    <input type="text"' . $idAttr . $nameAttr . $valAttr . $phAttr . $reqAttr . '>' . "\n";
            break;

        case 'email':
            echo '    <input type="email"' . $idAttr . $nameAttr . $valAttr . $phAttr . $reqAttr . '>' . "\n";
            break;

        case 'phone':
            echo '    <input type="tel"' . $idAttr . $nameAttr . $valAttr . $phAttr . $reqAttr . '>' . "\n";
            break;

        case 'url':
            echo '    <input type="url"' . $idAttr . $nameAttr . $valAttr . $phAttr . $reqAttr . '>' . "\n";
            break;

        case 'number':
            $min  = isset($settings['min']) ? ' min="' . (int)$settings['min'] . '"' : '';
            $max  = isset($settings['max']) ? ' max="' . (int)$settings['max'] . '"' : '';
            $step = isset($settings['step']) ? ' step="' . htmlspecialchars($settings['step'], ENT_QUOTES, 'UTF-8') . '"' : '';
            echo '    <input type="number"' . $idAttr . $nameAttr . $valAttr . $phAttr . $reqAttr . $min . $max . $step . '>' . "\n";
            break;

        case 'textarea':
            $rows = $settings['rows'] ?? 5;
            echo '    <textarea' . $idAttr . $nameAttr . $phAttr . $reqAttr . ' rows="' . (int)$rows . '">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</textarea>' . "\n";
            break;

        case 'select':
            echo '    <select' . $idAttr . $nameAttr . $reqAttr . '>' . "\n";
            if ($placeholder) {
                echo '      <option value="">' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '</option>' . "\n";
            }
            foreach ($choices as $choice) {
                $choiceVal   = is_array($choice) ? ($choice['value'] ?? '') : $choice;
                $choiceLabel = is_array($choice) ? ($choice['label'] ?? $choiceVal) : $choice;
                $selected    = ($choiceVal === $value) ? ' selected' : '';
                echo '      <option value="' . htmlspecialchars($choiceVal, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($choiceLabel, ENT_QUOTES, 'UTF-8') . '</option>' . "\n";
            }
            echo '    </select>' . "\n";
            break;

        case 'radio':
            echo '    <div class="outpost-radio-group">' . "\n";
            foreach ($choices as $i => $choice) {
                $choiceVal   = is_array($choice) ? ($choice['value'] ?? '') : $choice;
                $choiceLabel = is_array($choice) ? ($choice['label'] ?? $choiceVal) : $choice;
                $checked     = ($choiceVal === $value) ? ' checked' : '';
                $radioId     = $fieldId . '-' . $i;
                echo '      <label class="outpost-radio-label"><input type="radio" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" id="' . $radioId . '" value="' . htmlspecialchars($choiceVal, ENT_QUOTES, 'UTF-8') . '"' . $checked . $reqAttr . '> ' . htmlspecialchars($choiceLabel, ENT_QUOTES, 'UTF-8') . '</label>' . "\n";
            }
            echo '    </div>' . "\n";
            break;

        case 'checkbox':
            if (!empty($choices)) {
                // Multiple checkboxes
                echo '    <div class="outpost-checkbox-group">' . "\n";
                foreach ($choices as $i => $choice) {
                    $choiceVal   = is_array($choice) ? ($choice['value'] ?? '') : $choice;
                    $choiceLabel = is_array($choice) ? ($choice['label'] ?? $choiceVal) : $choice;
                    $checkId     = $fieldId . '-' . $i;
                    echo '      <label class="outpost-checkbox-label"><input type="checkbox" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '[]" id="' . $checkId . '" value="' . htmlspecialchars($choiceVal, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($choiceLabel, ENT_QUOTES, 'UTF-8') . '</label>' . "\n";
                }
                echo '    </div>' . "\n";
            } else {
                // Single toggle checkbox
                $checked = $value ? ' checked' : '';
                echo '    <label class="outpost-checkbox-label"><input type="checkbox"' . $idAttr . $nameAttr . ' value="1"' . $checked . '> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>' . "\n";
            }
            break;

        case 'date':
            echo '    <input type="date"' . $idAttr . $nameAttr . $valAttr . $reqAttr . '>' . "\n";
            break;

        case 'time':
            echo '    <input type="time"' . $idAttr . $nameAttr . $valAttr . $reqAttr . '>' . "\n";
            break;
    }

    // Description
    if ($description) {
        echo '    <p class="outpost-field-desc">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }

    echo '  </div>' . "\n";
}
