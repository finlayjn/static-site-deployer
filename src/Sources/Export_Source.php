<?php

namespace SSD\Sources;

/**
 * An export engine that produces a deployable static site.
 *
 * Sources fall into two lifecycles: directory-producing (e.g. Simply Static
 * writes files to disk and deployment happens on completion) and
 * browser-driven (e.g. the built-in crawler renders pages client-side and
 * uploads them directly). The interface accommodates both.
 */
interface Export_Source
{
    /** Stable identifier stored in settings. */
    public function slug(): string;

    /** Human-readable name for the settings UI. */
    public function label(): string;

    /** Whether this source's dependencies are present and usable here. */
    public function is_available(): bool;

    /** Explanation shown when {@see is_available()} is false. */
    public function unavailable_reason(): string;

    /** Registers any WordPress hooks the source needs while it is active. */
    public function register(): void;

    /**
     * Whether the source can be started from a server-side request (e.g. on
     * save or via the admin-post handler). Browser-driven sources return false;
     * they are triggered from admin JavaScript instead.
     */
    public function can_start_server_side(): bool;

    /**
     * Triggers a server-driven export. Browser-driven sources may treat this as
     * a no-op and start from their own admin UI instead.
     *
     * @return bool False when the export could not be started (status recorded).
     */
    public function start(): bool;

    /**
     * Renders the "Publish now" control for this source on the settings page.
     *
     * @param bool $has_creds Whether Cloudflare credentials are configured.
     */
    public function render_publish_control(bool $has_creds): void;
}
