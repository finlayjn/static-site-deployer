<?php

namespace SSD;

/**
 * Tracks deploy progress and history for display on the settings screen.
 *
 * Progress is written to an option during a deploy (which runs in a separate
 * request from the browser), and the settings page polls it over AJAX.
 */
class Status
{
    const PROGRESS_OPTION = 'ssd_deploy_progress';
    const HISTORY_OPTION = 'ssd_deploy_history';
    const HISTORY_LIMIT = 20;
    const AJAX_ACTION = 'ssd_status';

    /**
     * Records current progress.
     *
     * @param int    $percent 0-100.
     * @param string $message Human-readable step.
     * @param string $state   running|success|error|idle.
     */
    public static function set_progress(int $percent, string $message, string $state = 'running'): void
    {
        update_option(
            self::PROGRESS_OPTION,
            [
                'state'   => $state,
                'percent' => max(0, min(100, $percent)),
                'message' => $message,
                'time'    => time(),
            ],
            false
        );
    }

    /**
     * @return array{state:string,percent:int,message:string,time:int}
     */
    public static function get_progress(): array
    {
        $progress = get_option(self::PROGRESS_OPTION, []);
        $progress = is_array($progress) ? $progress : [];

        return [
            'state'   => (string) ($progress['state'] ?? 'idle'),
            'percent' => (int) ($progress['percent'] ?? 0),
            'message' => (string) ($progress['message'] ?? ''),
            'time'    => (int) ($progress['time'] ?? 0),
        ];
    }

    /**
     * Finalizes a deploy: sets the final progress state and prepends a history entry.
     *
     * @param string $status success|error.
     * @param string $message
     */
    public static function record_result(string $status, string $message): void
    {
        $percent = 'success' === $status ? 100 : self::get_progress()['percent'];
        self::set_progress($percent, $message, $status);

        $history = self::get_history();
        array_unshift($history, [
            'time'    => time(),
            'status'  => $status,
            'message' => $message,
        ]);
        update_option(self::HISTORY_OPTION, array_slice($history, 0, self::HISTORY_LIMIT), false);
    }

    /**
     * @return array<int,array{time:int,status:string,message:string}>
     */
    public static function get_history(): array
    {
        $history = get_option(self::HISTORY_OPTION, []);
        return is_array($history) ? $history : [];
    }

    /**
     * Registers the AJAX endpoint used by the settings-page poller.
     */
    public static function register_ajax(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'ajax_progress']);
    }

    /**
     * Returns current progress as JSON for the settings-page poller.
     */
    public static function ajax_progress(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }
        check_ajax_referer(self::AJAX_ACTION, 'nonce');
        wp_send_json_success(
            array_merge(
                self::get_progress(),
                ['history' => self::get_history_formatted()]
            )
        );
    }

    /**
     * History with a human-readable relative time, for the live table refresh.
     *
     * @return array<int,array{when:string,status:string,message:string}>
     */
    public static function get_history_formatted(): array
    {
        $out = [];
        foreach (self::get_history() as $entry) {
            $time = (int) ($entry['time'] ?? 0);
            $out[] = [
                'when'    => $time ? human_time_diff($time) . ' ' . __('ago', 'static-site-deployer') : '',
                'status'  => (string) ($entry['status'] ?? ''),
                'message' => (string) ($entry['message'] ?? ''),
            ];
        }
        return $out;
    }
}
