{{-- Role-driven vertical menu. Items come from App\Support\Navigation. --}}
<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">

    <div class="app-brand demo">
        <a href="{{ route('dashboard') }}" class="app-brand-link">
            <span class="app-brand-logo demo">
                <img src="{{ asset('assets/img/logo.png') }}" alt="{{ config('app.name') }} logo"
                     width="36" height="36" class="d-block rounded">
            </span>
            <span class="app-brand-text demo menu-text fw-bold ms-2">CRMS</span>
        </a>

    </div>

    <div class="menu-divider mt-0"></div>
    <div class="menu-inner-shadow"></div>

    <ul class="menu-inner py-1">
        @foreach (\App\Support\Navigation::sections() as $section)
            @if ($section['header'])
                <li class="menu-header small text-uppercase">
                    <span class="menu-header-text">{{ $section['header'] }}</span>
                </li>
            @endif

            @foreach ($section['items'] as $item)
                <li class="menu-item {{ $item['active'] ? 'active' : '' }}">
                    <a href="{{ route($item['route']) }}" class="menu-link"
                       aria-label="{{ $item['label'] }}" title="{{ $item['label'] }}">
                        <i class="menu-icon icon-base bx {{ $item['icon'] }}" aria-hidden="true"></i>
                        <div>{{ $item['label'] }}</div>
                    </a>
                </li>
            @endforeach
        @endforeach
    </ul>
</aside>
