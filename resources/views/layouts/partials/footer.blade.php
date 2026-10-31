<footer class="content-footer footer bg-footer-theme">
    <div class="container-xxl">
        <div class="footer-container d-flex align-items-center justify-content-end py-3 flex-md-row flex-column">
            <div class="text-muted small">
                Signed in as {{ auth()->user()->role->name }}
            </div>
        </div>
    </div>
</footer>
