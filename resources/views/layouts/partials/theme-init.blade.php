{{-- Apply the saved colour mode before first paint so a dark-mode user never sees a white flash. --}}
<script>
    (function () {
        var mode = 'light';
        try {
            mode = window.localStorage.getItem('crms.theme') || 'light';
        } catch (error) {
            // Storage can be unavailable in private or locked-down browsers.
        }

        var dark = mode === 'dark' || mode === 'oled' // "oled" was an earlier name for Dark
            || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

        document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
    })();
</script>
