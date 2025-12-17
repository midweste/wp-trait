<?php

declare(strict_types=1);

namespace WPTrait\Hook;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!trait_exists('WPTrait\Hook\AdminSettings')) {

    trait AdminSettings
    {
        public function bootAdminSettings($arg = [])
        {
            $defaults = [
                'method' => 'admin_settings',
                'priority' => 10,
            ];
            $args = wp_parse_args($arg, $defaults);
            $this->add_action('admin_init', $args['method'], $args['priority']);
        }

        public function admin_settings()
        {
            $this->settings_register($this->settings_fields());
        }

        /**
         * Define an array of settings_field arrays.
         *
         * @return array
         */
        abstract public function settings_fields(): array;

        /**
         * Define a settings field array for ->settings_fields abstract method.
         *
         * @param string $id
         * @param string $title
         * @param array $values
         * @return array
         */
        public function setting_field(string $id, string $title = '', array $values = []): array
        {
            unset($values['id'], $values['title']);
            return array_merge([
                'id' => $id,
                'title' => $title,
                'type' => 'text',
                'enum' => [],
                'default' => '',
                'description' => '',
                'section' => 'default',
                'attributes' => [],
                'disabled' => false,
                'readonly' => false,
                'required' => false,
                'sanitize' => 'sanitize_text_field',
                'validation' => 'is_string',
                'args' => [],
            ], $values);
        }

        /**
         * Get the default settings values.
         *
         * @return array
         */
        public function settings_defaults(): array
        {
            static $cache = [];
            $name = get_class($this);
            if (!empty($cache[$name])) {
                return $cache[$name];
            }

            $defaults = [];
            foreach ($this->settings_fields() as $field) {
                $default = $field['default'] ?? '';
                if ($default instanceof \Closure) {
                    $default = call_user_func($default);
                }
                $defaults[$field['id']] = $default;
            }
            $cache[$name] = $defaults;
            return $defaults;
        }

        /**
         * Get the required settings fields.
         *
         * @return array
         */
        public function settings_required(): array
        {
            static $cache = [];
            $name = get_class($this);
            if (!empty($cache[$name])) {
                return $cache[$name];
            }

            $fields = [];
            foreach ($this->settings_fields() as $field) {
                $required = $field['required'] ?? false;
                if ($required instanceof \Closure) {
                    $required = call_user_func($required);
                }
                if ($required === true) {
                    $fields[$field['id']] = $required;
                }
            }
            $cache[$name] = $fields;
            return $fields;
        }

        /**
         * Get a default setting value.
         *
         * @param string $key
         * @return mixed
         */
        public function setting_default(string $key): mixed
        {
            $defaults = $this->settings_defaults();
            if ($key === '' || !isset($defaults[$key])) {
                return null;
            }
            return $defaults[$key];
        }

        /**
         * Get the settings values.
         *
         * @return array
         */
        public function settings(): mixed
        {
            $option = $this->option($this->plugin->slug);
            $defaults = $this->settings_defaults();
            $settings = $option->get($defaults, $this->plugin->slug);
            $merged = array_merge($defaults, $settings);
            return $merged;
        }

        /**
         * Get a setting value.
         *
         * @param string $key
         * @param mixed $default
         * @return mixed
         */
        public function setting(string $key, $default = null): mixed
        {
            $settings = $this->settings();
            if ($key == '' || !isset($settings[$key])) {
                return $default;
            }
            return $settings[$key];
        }

        /**
         * Set a specific setting value.
         *
         * @param string $key
         * @param mixed $value
         * @return bool
         */
        public function setting_set(string $key, $value): bool
        {
            if (empty($key)) {
                return false;
            }

            $settings = $this->settings();
            $settings[$key] = $value;

            $option = $this->option($this->plugin->slug);
            return $option->update($settings);
        }

        /**
         * Delete a specific setting value (resets to default).
         *
         * @param string $key
         * @return bool
         */
        public function setting_delete(string $key): bool
        {
            if (empty($key)) {
                return false;
            }

            $settings = $this->settings();
            if (isset($settings[$key])) {
                unset($settings[$key]);

                $option = $this->option($this->plugin->slug);
                return $option->update($settings);
            }

            return true; // Key didn't exist, consider it successful
        }

        /**
         * Clear all settings (resets to defaults).
         *
         * @return bool
         */
        public function settings_clear(): bool
        {
            $option = $this->option($this->plugin->slug);
            return $option->delete();
        }

        /**
         * Reset all settings to defaults.
         *
         * @return bool
         */
        public function settings_reset(): bool
        {
            $defaults = $this->settings_defaults();
            $option = $this->option($this->plugin->slug);
            return $option->update($defaults);
        }

        /**
         * Sanitize and validate the settings.
         *
         * @param array $input
         * @return array
         */
        public function settings_sanitize(array $input): array
        {
            // get validators and sanitizers
            $sanitizers = [];
            $validators = [];
            foreach ($this->settings_fields() as $field) {
                $sanitizers[$field['id']] = $field['sanitize'] ?? 'sanitize_text_field';
                $validators[$field['id']] = $field['validation'] ?? '';
            }

            foreach ($input as $key => $value) {
                // remove any extra keys
                if (!isset($sanitizers[$key])) {
                    unset($input[$key]);
                    continue;
                }

                // sanitize
                $sanitize_callback = $sanitizers[$key] ?? '';
                if (is_callable($sanitize_callback)) {
                    $input[$key] = call_user_func($sanitize_callback, $value);
                } else {
                    $input[$key] = sanitize_text_field($value);
                }

                // validate
                $validate_callback = $validators[$key] ?? '';
                if (!is_callable($validate_callback)) {
                    continue;
                }
                if (call_user_func($validate_callback, $input[$key]) !== true) {
                    add_settings_error(
                        $this->plugin->slug,
                        $key,
                        sprintf('Value is not correct for %s.', $key),
                        'error'
                    );
                    unset($input[$key]);
                }
            }

            return $input;
        }

        /**
         * Register the settings.
         *
         * @param array $fields
         * @return void
         */
        public function settings_register(array $fields = []): void
        {
            $fields = !empty($fields) ? $fields : $this->settings_fields();

            // validate fields
            if (empty($fields)) {
                throw new \Exception('Fields are required.');
            }
            foreach ($fields as $field) {
                if (empty($field['id']) && !$field['id'] instanceof \Closure) {
                    throw new \Exception('Field id is required.');
                }
            }

            // register the settings group and fields
            $slug = $this->plugin->slug;

            register_setting($slug, $slug, [
                'sanitize_callback' => [$this, 'settings_sanitize'],
                'default' => $this->settings_defaults(),
                'show_in_rest' => false,
            ]);

            $callback = function ($field, $key, $default = null) {
                $default = is_null($default) ? '' : $default;
                if (!isset($field[$key])) {
                    return $default;
                }
                if ($field[$key] instanceof \Closure) {
                    return call_user_func($field[$key]);
                }
                return $field[$key];
            };

            // sections
            $sections = [];
            add_settings_section('default', '', null, $slug);
            foreach ($fields as $field) {
                $section = $callback($field, 'section', 'default');
                if (isset($sections[$section]) || $section === 'default') {
                    continue;
                }
                add_settings_section($section, $section, null, $slug);
                $sections[$section] = $section;
            }

            // fields
            foreach ($fields as $field) {
                $id = $callback($field, 'id');
                $title = $callback($field, 'title', $id);
                $type = $callback($field, 'type', 'text');
                $attributes = $callback($field, 'attributes', []);
                $description = $callback($field, 'description', '');
                $default = $callback($field, 'default', '');
                $enum = $callback($field, 'enum', []);
                $section = $callback($field, 'section', 'default');
                $required = $callback($field, 'required', false);
                $readonly = $callback($field, 'readonly', false);
                $disabled = $callback($field, 'disabled', false);
                $args = $callback($field, 'args', []);
                $args['class'] = isset($args['class']) ? $args['class'] : '';
                $args['class'] = trim($args['class'] . ' settings-field-row setting-field-' . $type);

                add_settings_field(
                    $id,
                    $title,
                    function () use ($id, $type, $title, $enum, $attributes, $description, $default, $required, $readonly, $disabled) {
                        echo $this->settings_render_field($id, $type, $title, $enum, $default, $description, $attributes, $required, $readonly, $disabled);
                    },
                    $slug,
                    $section,
                    $args
                );
            }
        }

        /**
         * Render the settings field.
         *
         * @param string $key
         * @param string $type
         * @param string $title
         * @param array $enum
         * @param mixed $default
         * @param string $description
         * @param array $attributes
         * @param bool $required
         * @param bool $readonly
         * @param bool $disabled
         * @return string
         */
        public function settings_render_field(string $key, string $type = 'text', $title = '', array $enum = [], $default = '', $description = '', array $attributes = [], bool $required = false, bool $readonly = false, bool $disabled = false): string
        {
            $slug = $this->plugin->slug;
            $value = $this->setting($key, $default);

            // attributes
            $attrs_array = array_merge([], $attributes);
            $attrs_array['aria-describedby'] = sprintf('%s-%s', $slug, $key);
            $attrs_array['title'] = $description;
            if ($required === true) {
                $attrs_array['required'] = 'required';
            }
            if ($readonly === true) {
                $attrs_array['readonly'] = 'readonly';
            }
            if ($disabled === true) {
                $attrs_array['disabled'] = 'disabled';
            }
            $attrs = '';
            foreach ($attrs_array as $attr_key => $attr_value) {
                $attrs .= sprintf('%s="%s" ', $attr_key, esc_attr($attr_value));
            }
            $attrs = trim($attrs);

            // input fields
            $html = "<fieldset class='field-type-{$type}'>";
            $html .= "<legend class='screen-reader-text'><span>{$title}</span></legend>";

            // show required *
            // if ($required === true) {
            //     $html .= sprintf('<strong class="required">*</strong>');
            // }

            if ($type === 'checkbox') {
                foreach ($enum as $checkbox_key => $checkbox_label) {
                    $checked = $value === (string) $checkbox_key ? 'checked' : '';
                    $html .= "<label><input type='{$type}' name='{$slug}[{$key}]' value='{$checkbox_key}' {$checked} {$attrs} />{$title}</label>";
                    break;
                }
                // $html .= "<label for='{$slug}[{$key}]'><input type='{$type}' name='{$slug}[{$key}]' value='enabled' {$checked} {$attrs} />{$label}</label>";
            } elseif ($type === 'checkbox_group') {
                foreach ($enum as $checkbox_key => $checkbox_label) {
                    $checked = isset($value[$checkbox_key]) && $value[$checkbox_key] === 'enabled' ? 'checked' : '';
                    $html .= "<label><input type='checkbox' name='{$slug}[{$key}][{$checkbox_key}]' value='enabled' {$checked} {$attrs} /> {$checkbox_label}</label><br />";
                }
            } elseif ($type === 'radio_group' || $type === 'radio') {
                foreach ($enum as $radio_key => $radio_label) {
                    // Better type handling for radio button comparison
                    $checked_value = $value;
                    if (is_bool($value)) {
                        $checked_value = (int) $value;
                    } elseif (is_string($value) && is_numeric($value)) {
                        $checked_value = (int) $value;
                    }

                    $checked = $checked_value === $radio_key ? 'checked' : '';
                    $html .= "<label><input type='radio' name='{$slug}[{$key}]' value='{$radio_key}' {$checked} {$attrs} /> {$radio_label}</label>";
                }
            } elseif ($type === 'select') {
                $html .= "<select name='{$slug}[{$key}]' {$attrs}>";
                foreach ($enum as $option_key => $option_label) {
                    $selected = $value === (string) $option_key ? 'selected' : '';
                    $html .= "<option value='{$option_key}' {$selected}>{$option_label}</option>";
                }
                $html .= "</select>";
            } elseif ($type === 'number') {
                $html .= "<input type='{$type}' name='{$slug}[{$key}]' value='{$value}' {$attrs} />";
            } elseif ($type === 'textarea') {
                $html .= "<textarea name='{$slug}[{$key}]' {$attrs}>{$value}</textarea>";
            } elseif ($type === 'hidden') {
                $html .= "<input type='{$type}' name='{$slug}[{$key}]' value='{$value}' {$attrs} />";
            } else {
                $html .= "<input type='{$type}' name='{$slug}[{$key}]' value='{$value}' {$attrs} />";
            }

            // show required *
            if ($required === true) {
                $html .= sprintf('<strong class="required">*</strong>');
            }

            // description
            if (!empty($description)) {
                $html .= sprintf('<p id="%s" class="description">%s</p>', $slug . '-' . $key, $description);
            }

            $html .= '</fieldset>';
            return $html;
        }

        /**
         * Render the settings form.
         *
         * @param string $title
         * @param string $description
         * @return string
         */
        public function settings_render(string $title = '', string $description = ''): string
        {
            $buffer = function (callable $callback, $args = []) {
                ob_start();
                call_user_func_array($callback, $args);
                return ob_get_clean();
            };

            $slug = $this->plugin->slug;
            $fields = $buffer('settings_fields', [$slug]);
            $sections = $buffer('do_settings_sections', [$slug]);
            $button = $buffer('submit_button');

            $id = $slug;
            $title = !empty($title) ? sprintf('<h2>%s</h2>', $title) : '';
            $description = !empty($description) ? sprintf('<p>%s</p>', $description) : '';

            $form = <<<HTML
            <div id="{$id}" class="wrap">
                {$title}
                {$description}
                <style>
                    #{$id} input, #{$id} select, #{$id} textarea {
                        display: inline;
                    }

                    #{$id} .field-type-radio label, #{$id} .field-type-radio_group label {
                        padding-right: 10px;
                        margin-right: 15px;
                    }
                    #{$id} .form-table td fieldset label {
                        margin-top: 0 !important;
                    }

                    #{$id} input[type="text"], #{$id} textarea {
                        width: 95%;
                    }
                    #{$id} textarea {
                        height: 80px;
                    }
                    #{$id} input[type="number"], #{$id} select {
                        width: 25%;
                    }
                    #{$id} .setting-field-hidden th, #{$id} .setting-field-hidden td {
                        margin: 0;
                        padding: 0;
                    }

                    #{$id} strong.required {
                        padding-left: 5px;
                        vertical-align: top;
                        color: #a00;
                    }
                </style>
                <form method="post" action="options.php" class="settings-fields-form">
                    {$fields}
                    {$sections}
                    {$button}
                </form>
            </div>
            HTML;
            return $form;
        }
    }
}
