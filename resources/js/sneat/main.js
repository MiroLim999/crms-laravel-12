/**
 * Main
 */

'use strict';

let menu,
  animate;
document.addEventListener('DOMContentLoaded', function () {
  // class for ios specific styles
  if (navigator.userAgent.match(/iPhone|iPad|iPod/i)) {
    document.body.classList.add('ios');
  }
});

(function () {
  // Initialize menu
  //-----------------

  let layoutMenuEl = document.querySelectorAll('#layout-menu');
  layoutMenuEl.forEach(function (element) {
    menu = new Menu(element, {
      orientation: 'vertical',
      closeChildren: false
    });
    // Change parameter to true if you want scroll animation
    window.Helpers.scrollToActive((animate = false));
    window.Helpers.mainMenu = menu;
  });

  const desktopSidebarStorageKey = 'crms.sidebar.hidden';
  const root = document.documentElement;
  const menuControls = document.querySelectorAll('[data-menu-toggle-control]');
  let desktopSidebarHidden = false;
  let lastMenuOpener = null;

  try {
    desktopSidebarHidden = window.localStorage.getItem(desktopSidebarStorageKey) === 'true';
  } catch {
    // The sidebar remains usable when browser storage is unavailable.
  }

  // Keep the stored desktop preference on the root while the responsive drawer
  // uses its separate layout-menu-expanded state below 1200px.
  root.classList.toggle('layout-menu-collapsed', desktopSidebarHidden);
  window.Helpers.setCollapsed(window.Helpers.isSmallScreen() ? true : desktopSidebarHidden, false);

  const syncMenuControls = collapsed => {
    const expanded = !collapsed;
    const smallScreen = window.Helpers.isSmallScreen();

    menuControls.forEach(control => {
      control.setAttribute('aria-expanded', String(expanded));
      const label = smallScreen
        ? (expanded ? 'Close navigation' : 'Open navigation')
        : (expanded ? 'Collapse navigation' : 'Expand navigation');
      control.setAttribute('aria-label', label);
      control.title = label;

      const icon = control.querySelector('.icon-base');
      if (icon) {
        icon.classList.toggle('bx-chevron-left', !collapsed);
        icon.classList.toggle('bx-chevron-right', collapsed);
      }
    });
  };

  const rememberDesktopState = hidden => {
    desktopSidebarHidden = hidden;

    try {
      window.localStorage.setItem(desktopSidebarStorageKey, String(hidden));
    } catch {
      // A private or locked-down browser may reject local storage writes.
    }
  };

  const moveFocusAfterToggle = (collapsed, smallScreen, trigger) => {
    const isControl = trigger?.matches('[data-menu-toggle-control]');

    if (!collapsed && isControl) {
      lastMenuOpener = trigger;
      if (smallScreen) {
        window.setTimeout(() => document.querySelector('#layout-menu .menu-inner .menu-link')?.focus(), 320);
      }
      return;
    }

    if (collapsed && (smallScreen || trigger?.closest('#layout-menu'))) {
      const fallback = document.querySelector('.global-sidebar-toggle');
      window.setTimeout(() => (lastMenuOpener ?? fallback)?.focus(), 320);
    }
  };

  // Initialize menu togglers and bind click on each
  let menuToggler = document.querySelectorAll('.layout-menu-toggle');
  menuToggler.forEach(item => {
    item.addEventListener('click', event => {
      event.preventDefault();
      const smallScreen = window.Helpers.isSmallScreen();
      const collapsed = !window.Helpers.isCollapsed();

      window.Helpers.setCollapsed(collapsed);
      if (!smallScreen) rememberDesktopState(collapsed);
      syncMenuControls(collapsed);
      moveFocusAfterToggle(collapsed, smallScreen, event.currentTarget);
    });
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || !window.Helpers.isSmallScreen() || window.Helpers.isCollapsed()) return;

    window.Helpers.setCollapsed(true);
    syncMenuControls(true);
    window.setTimeout(() => lastMenuOpener?.focus(), 320);
  });

  let wasSmallScreen = window.Helpers.isSmallScreen();
  let responsiveMenuTimer = null;
  window.addEventListener('resize', () => {
    window.clearTimeout(responsiveMenuTimer);
    responsiveMenuTimer = window.setTimeout(() => {
      const smallScreen = window.Helpers.isSmallScreen();

      if (smallScreen !== wasSmallScreen) {
        root.classList.remove('layout-menu-expanded');
        window.Helpers.setCollapsed(smallScreen ? true : desktopSidebarHidden, false);
        wasSmallScreen = smallScreen;
      }

      syncMenuControls(window.Helpers.isCollapsed());
    }, 220);
  });

  syncMenuControls(window.Helpers.isCollapsed());

  // Display in main menu when menu scrolls
  let menuInnerContainer = document.getElementsByClassName('menu-inner'),
    menuInnerShadow = document.getElementsByClassName('menu-inner-shadow')[0];
  if (menuInnerContainer.length > 0 && menuInnerShadow) {
    menuInnerContainer[0].addEventListener('ps-scroll-y', function () {
      if (this.querySelector('.ps__thumb-y').offsetTop) {
        menuInnerShadow.style.display = 'block';
      } else {
        menuInnerShadow.style.display = 'none';
      }
    });
  }

  // Init helpers & misc
  // --------------------

  // Init BS Tooltip
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Accordion active class
  const accordionActiveFunction = function (e) {
    if (e.type == 'show.bs.collapse' || e.type == 'show.bs.collapse') {
      e.target.closest('.accordion-item').classList.add('active');
    } else {
      e.target.closest('.accordion-item').classList.remove('active');
    }
  };

  const accordionTriggerList = [].slice.call(document.querySelectorAll('.accordion'));
  const accordionList = accordionTriggerList.map(function (accordionTriggerEl) {
    accordionTriggerEl.addEventListener('show.bs.collapse', accordionActiveFunction);
    accordionTriggerEl.addEventListener('hide.bs.collapse', accordionActiveFunction);
  });

  // Auto update layout based on screen size
  window.Helpers.setAutoUpdate(true);

  // Toggle Password Visibility
  window.Helpers.initPasswordToggle();

  // Speech To Text
  window.Helpers.initSpeechToText();

})();
// Utils
function isMacOS() {
  return /Mac|iPod|iPhone|iPad/.test(navigator.userAgent);
}
