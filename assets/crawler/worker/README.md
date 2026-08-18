# Cloudflare API CORS relay

The built-in crawler deploys to Cloudflare from your browser. Because
`api.cloudflare.com` does not send CORS headers, browser requests must go
through a small relay Worker. It **stores no credentials** — your API token is
passed through from the browser on each request.

## Deploy

1. Install [wrangler](https://developers.cloudflare.com/workers/wrangler/) and
   log in:

   ```bash
   npm install -g wrangler
   wrangler login
   ```

2. Edit `wrangler.toml` and set `ALLOWED_ORIGINS` to your WordPress site
   origin (e.g. `https://example.com`, or
   `https://playground.wordpress.net` when publishing from Playground).

3. Deploy from this directory:

   ```bash
   cd assets/crawler/worker
   wrangler deploy
   ```

4. Copy the deployed URL (e.g. `https://ssd-cloudflare-relay.<you>.workers.dev`)
   into the plugin's **Cloudflare API relay URL** setting.

## Security notes

- Keep `ALLOWED_ORIGINS` restricted to origins you control so the relay cannot
  be used as an open proxy.
- The relay only forwards requests to `https://api.cloudflare.com/client/v4/*`.
- Your Cloudflare API token is sent from the browser (admin-only page) and
  relayed to Cloudflare; it is never stored by the relay. Scope the token to
  the minimum permission needed (Workers Scripts: Edit).
