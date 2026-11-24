import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/login.css', 'resources/js/login.js', 'resources/css/register.css', 'resources/js/register.js', 'resources/css/reference_ui/01_splash_screen.css', 'resources/css/reference_ui/10_welcome_how_it_works.css', 'resources/css/reference_ui/02_register_account.css', 'resources/js/reference_ui/02_register_account.js', 'resources/css/reference_ui/03_login.css', 'resources/js/reference_ui/03_login.js', 'resources/css/reference_ui/11_forgot_password.css', 'resources/js/reference_ui/11_forgot_password.js', 'resources/css/reference_ui/reset_password.css', 'resources/js/reference_ui/reset_password.js', 'resources/css/reference_ui/20_my_events_list.css', 'resources/js/reference_ui/20_my_events_list.js', 'resources/css/reference_ui/04_choose_event_type.css', 'resources/js/reference_ui/04_choose_event_type.js', 'resources/css/reference_ui/05_event_details.css', 'resources/js/reference_ui/05_event_details.js', 'resources/css/reference_ui/30_event_setup_progress.css', 'resources/js/reference_ui/30_event_setup_progress.js', 'resources/css/reference_ui/12_event_dashboard.css', 'resources/js/reference_ui/12_event_dashboard.js', 'resources/css/reference_ui/13_choose_invitation_template.css', 'resources/js/reference_ui/13_choose_invitation_template.js', 'resources/css/reference_ui/invitation_studio.css', 'resources/css/reference_ui/invitation_steps.css', 'resources/js/reference_ui/14_edit_invitation_card.js', 'resources/js/reference_ui/31_select_invitation_recipients.js', 'resources/js/reference_ui/06_invitation_preview.js', 'resources/js/reference_ui/customer_guests.js', 'resources/css/reference_ui/customer_manage.css', 'resources/css/reference_ui/customer_shell.css', 'resources/js/reference_ui/customer_shell.js', 'resources/js/reference_ui/customer_guests_add.js', 'resources/js/reference_ui/customer_share.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
