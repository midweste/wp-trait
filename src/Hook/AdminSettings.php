<?php

declare(strict_types=1);

namespace WPTrait\Hook;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!trait_exists('WPTrait\Hook\AdminSettings')) {

    trait AdminSettings
    {
        use AdminMenu, Notice;

        private array $fields = [];

        public function bootAdminSettings($arg = [])
        {
            $defaults = [
                'method' => 'admin_settings',
                'priority' => 10,
            ];
            $args = wp_parse_args($arg, $defaults);
            $this->add_action('admin_init', $args['method'], $args['priority']);
        }

        public function admin_settings() {}

        public function settings_sanitize(array $input): array
        {
            // get validators and sanitizers
            $sanitizers = [];
            $validators = [];
            foreach ($this->fields as $field) {
                $field_sanitize_callback = $field['sanitize_callback'] ?? 'sanitize_text_field';
                $sanitizers[$field['id']] = $field_sanitize_callback;
                $field_validation_callback = $field['validation_callback'] ?? '';
                $validators[$field['id']] = $field_validation_callback;
            }

            foreach ($input as $key => $value) {
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

        public function register_settings(array $fields = []): void
        {
            // validate fields
            if (empty($fields)) {
                throw new \Exception('Fields are required.');
            }
            foreach ($fields as $field) {
                if (empty($field['id'])) {
                    throw new \Exception('Field id is required.');
                }
            }

            // register the settings group and fields
            $this->fields = $fields;
            $slug = $this->plugin->slug;

            register_setting($slug, $slug, [
                'sanitize_callback' => [$this, 'settings_sanitize'],
                'default' => $this->settings_defaults(),
                'show_in_rest' => false,
            ]);

            add_settings_section('default', 'Settings', null, $slug);

            foreach ($fields as $field) {
                if (empty($field['id'])) {
                    throw new \Exception('Field id is required.');
                }

                $id = $field['id'];
                $label = $field['label'] ?? $id;
                $type = $field['type'] ?? 'text';
                $attributes = $field['attributes'] ?? [];
                $description = $field['description'] ?? '';
                $default = $field['default'] ?? '';
                $enum = $field['enum'] ?? [];

                add_settings_field(
                    $id,
                    $label,
                    function () use ($id, $type, $enum, $attributes, $description, $default) {
                        echo $this->settings_render_field($id, $type, $enum, $default, $description, $attributes);
                    },
                    $slug
                );
            }
        }

        public function settings(): array
        {
            return get_option($this->plugin->slug, $this->settings_defaults());
        }

        public function settings_defaults(): array
        {
            $defaults = [];
            foreach ($this->fields as $field) {
                $default = $field['default'] ?? '';
                $defaults[$field['id']] = $default;
            }
            return $defaults;
        }

        public function settings_render_field(string $key, string $type = 'text', array $enum = [], $default = '', $description = '', array $attributes = []): string
        {
            $slug = $this->plugin->slug;
            $settings = get_option($slug, $this->settings_defaults());
            $value = isset($settings[$key]) ? $settings[$key] : $default;

            // attributes
            $attrs_array = [];
            $attrs_array = array_merge($attrs_array, $attributes);
            $attrs_array['aria-describedby'] = sprintf('%s-%s', $slug, $key);
            $attrs_array['title'] = $description;
            $attrs = '';
            foreach ($attrs_array as $attr_key => $attr_value) {
                $attrs .= sprintf('%s="%s" ', $attr_key, esc_attr($attr_value));
            }
            $attrs = trim($attrs);

            // input field
            $html = '';
            if ($type === 'checkbox') {
                $checked = $value === 'enabled' ? 'checked' : '';
                $html .= "<input type='{$type}' name='{$slug}[{$key}]' value='enabled' {$checked} {$attrs} />";
            } elseif ($type === 'checkbox_group') {
                foreach ($enum as $checkbox_key => $checkbox_label) {
                    $checked = isset($value[$checkbox_key]) && $value[$checkbox_key] === 'enabled' ? 'checked' : '';
                    $html .= "<label><input type='checkbox' name='{$slug}[{$key}][{$checkbox_key}]' value='enabled' {$checked} {$attrs} /> {$checkbox_label}</label><br />";
                }
            } else {
                $html .= "<input type='{$type}' name='{$slug}[{$key}]' value='{$value}' {$attrs} />";
            }

            // description
            if (!empty($description)) {
                $html .= sprintf('<p id="%s">%s</p>', $slug . '-' . $key, $description);
            }
            return $html;
        }

        public function render_settings(string $title = ''): string
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

            $form = <<<HTML
            <div id="{$id}" class="wrap">
                {$title}
                <style>
                    #{$id} input {
                        margin-right: 10px;
                    }
                    #{$id} input[type="text"] {
                        width: 100%;
                    }

                    #{$id} input[type="number"] {
                        width: 25%;
                    }
                </style>
                <form method="post" action="options.php">
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
