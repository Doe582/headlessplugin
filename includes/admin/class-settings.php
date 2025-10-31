<?php

class RESTBridge_Settings {

    public function add_menu_page() {
        add_menu_page(
            'REST Bridge Settings',
            'REST Bridge',
            'manage_options',
            'rest-bridge-plugin',
            [$this, 'render_settings_page'],
            'dashicons-rest-api'
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>REST Bridge Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('rest_bridge_settings_group');
                do_settings_sections('rest-bridge-plugin');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
