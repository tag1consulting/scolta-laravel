{{--
    Amazee.ai settings page.

    Multi-step Alpine.js UI for the Amazee.ai connection flow:
      - start:        email input + "Start trial" / "Sign in" buttons
      - verification: code input
      - region:       region selection list
      - connected:    status display + disconnect button

    Routes expect JSON responses. Step transitions are driven client-side.
    The PHP-rendered `step` data attribute provides the initial state so
    the page works on first load without an extra AJAX round-trip.
--}}
<div x-data="amazeeSettings('{{ $step }}', '{{ $email }}')" x-init="init()">

    {{-- Connected --}}
    <template x-if="step === 'connected'">
        <div>
            <p>
                Connected to Amazee.ai
                <template x-if="region">
                    <span>(region: <strong x-text="region"></strong>)</span>
                </template>.
            </p>
            <button type="button" @click="disconnect()" :disabled="loading" class="btn btn-secondary">
                <span x-show="loading">Disconnecting…</span>
                <span x-show="!loading">Disconnect</span>
            </button>
            <p x-show="error" x-text="error" class="text-danger mt-2"></p>
        </div>
    </template>

    {{-- Start: email + trial/sign-in --}}
    <template x-if="step === 'start'">
        <div>
            <p>Connect Scolta to Amazee.ai for privacy-respecting, budget-aware AI search.</p>
            <div class="mb-3">
                <label for="amazee-email">Email address</label>
                <input type="email" id="amazee-email" x-model="email" class="form-control" />
            </div>
            <p x-show="error" x-text="error" class="text-danger"></p>
            <button type="button" @click="startTrial()" :disabled="loading" class="btn btn-primary me-2">
                <span x-show="loading">Starting…</span>
                <span x-show="!loading">Start free trial</span>
            </button>
            <button type="button" @click="requestCode()" :disabled="loading" class="btn btn-secondary">
                Sign in to existing account
            </button>
        </div>
    </template>

    {{-- Verification code --}}
    <template x-if="step === 'verification'">
        <div>
            <p>A verification code has been sent to <strong x-text="email"></strong>. Enter it below.</p>
            <div class="mb-3">
                <label for="amazee-code">Verification code</label>
                <input type="text" id="amazee-code" x-model="code" autocomplete="one-time-code" class="form-control" />
            </div>
            <p x-show="error" x-text="error" class="text-danger"></p>
            <button type="button" @click="verifyCode()" :disabled="loading" class="btn btn-primary me-2">
                <span x-show="loading">Verifying…</span>
                <span x-show="!loading">Verify code</span>
            </button>
            <button type="button" @click="step = 'start'; error = ''" :disabled="loading" class="btn btn-secondary">
                Back
            </button>
        </div>
    </template>

    {{-- Region selection --}}
    <template x-if="step === 'region'">
        <div>
            <p>Select the region where your AI requests will be processed.</p>
            <p x-show="loadingRegions">Loading regions…</p>
            <div x-show="!loadingRegions && regions.length > 0" class="mb-3">
                <template x-for="r in regions" :key="r.id">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" :id="'region-' + r.id"
                               :value="r.id" x-model="selectedRegion" />
                        <label class="form-check-label" :for="'region-' + r.id" x-text="r.name ?? r.id"></label>
                    </div>
                </template>
            </div>
            <p x-show="error" x-text="error" class="text-danger"></p>
            <button type="button" @click="connectRegion()" :disabled="loading || !selectedRegion" class="btn btn-primary me-2">
                <span x-show="loading">Connecting…</span>
                <span x-show="!loading">Connect</span>
            </button>
            <button type="button" @click="step = 'start'; error = ''" :disabled="loading" class="btn btn-secondary">
                Back
            </button>
        </div>
    </template>

</div>

@php
    $routes = [
        'show'        => route('scolta.amazee.show'),
        'trial'       => route('scolta.amazee.trial'),
        'requestCode' => route('scolta.amazee.request-code'),
        'verifyCode'  => route('scolta.amazee.verify-code'),
        'regions'     => route('scolta.amazee.regions'),
        'connect'     => route('scolta.amazee.connect'),
        'disconnect'  => route('scolta.amazee.disconnect'),
    ];
@endphp

<script>
function amazeeSettings(initialStep, initialEmail) {
    return {
        step: initialStep || 'start',
        email: initialEmail || '',
        code: '',
        region: @json($region ?? null),
        regions: [],
        selectedRegion: '',
        loading: false,
        loadingRegions: false,
        error: '',
        routes: @json($routes),

        async init() {
            if (this.step === 'region') {
                await this.fetchRegions();
            }
        },

        async startTrial() {
            this.error = '';
            this.loading = true;
            try {
                const res = await this.post(this.routes.trial, { email: this.email });
                this.step = res.step;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async requestCode() {
            this.error = '';
            this.loading = true;
            try {
                const res = await this.post(this.routes.requestCode, { email: this.email });
                this.step = res.step;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async verifyCode() {
            this.error = '';
            this.loading = true;
            try {
                const res = await this.post(this.routes.verifyCode, { code: this.code });
                this.step = res.step;
                await this.fetchRegions();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async fetchRegions() {
            this.loadingRegions = true;
            try {
                const res = await this.get(this.routes.regions);
                this.regions = res.regions || [];
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loadingRegions = false;
            }
        },

        async connectRegion() {
            this.error = '';
            this.loading = true;
            try {
                const res = await this.post(this.routes.connect, { region_id: this.selectedRegion });
                this.step = res.step;
                const cred = this.regions.find(r => r.id === this.selectedRegion);
                this.region = cred ? (cred.name ?? cred.id) : this.selectedRegion;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async disconnect() {
            this.error = '';
            this.loading = true;
            try {
                const res = await this.request('DELETE', this.routes.disconnect, {});
                this.step = res.step;
                this.region = null;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async get(url) {
            const resp = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            return this.parseResponse(resp);
        },

        async post(url, body) {
            return this.request('POST', url, body);
        },

        async request(method, url, body) {
            const headers = {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            };
            const resp = await fetch(url, { method, headers, body: JSON.stringify(body) });
            return this.parseResponse(resp);
        },

        async parseResponse(resp) {
            const data = await resp.json().catch(() => ({}));
            if (! resp.ok) {
                const msg = data.message ?? data.error ?? `Request failed (${resp.status})`;
                throw new Error(msg);
            }
            return data;
        },
    };
}
</script>
