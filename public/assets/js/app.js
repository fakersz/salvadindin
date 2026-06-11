document.addEventListener('DOMContentLoaded', () => {
  const currentTheme = () => document.documentElement.dataset.theme || 'light';

  const syncThemeButtons = () => {
    const isDark = currentTheme() === 'dark';

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      button.setAttribute('aria-pressed', String(isDark));
      button.setAttribute('aria-label', isDark ? 'Ativar modo claro' : 'Ativar modo escuro');
      button.setAttribute('title', isDark ? 'Modo claro' : 'Modo escuro');
      button.textContent = isDark ? '☀' : '☾';
    });
  };

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const nextTheme = currentTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = nextTheme;
      localStorage.setItem('salvadindin-theme', nextTheme);
      syncThemeButtons();
    });
  });

  syncThemeButtons();

  const sidebar = document.querySelector('.sidebar');

  if (sidebar && !document.querySelector('.mobile-menu-btn')) {
    const menuButton = document.createElement('button');
    menuButton.type = 'button';
    menuButton.className = 'mobile-menu-btn';
    menuButton.setAttribute('aria-label', 'Abrir menu');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.innerHTML = '<span></span><span></span><span></span>';
    document.body.prepend(menuButton);

    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    const closeSidebar = () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('open');
      document.body.classList.remove('sidebar-open');
      menuButton.setAttribute('aria-label', 'Abrir menu');
      menuButton.setAttribute('aria-expanded', 'false');
    };

    const openSidebar = () => {
      sidebar.classList.add('open');
      overlay.classList.add('open');
      document.body.classList.add('sidebar-open');
      menuButton.setAttribute('aria-label', 'Fechar menu');
      menuButton.setAttribute('aria-expanded', 'true');
    };

    menuButton.addEventListener('click', () => {
      if (sidebar.classList.contains('open')) {
        closeSidebar();
        return;
      }

      openSidebar();
    });

    overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeSidebar);
    });

    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeSidebar();
      }
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 980) {
        closeSidebar();
      }
    });
  }

  if (sidebar && !document.querySelector('[data-theme-toggle]')) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'nav-item theme-toggle';
    button.dataset.themeToggle = 'true';
    sidebar.insertBefore(button, sidebar.querySelector('.nav-item.muted'));

    button.addEventListener('click', () => {
      const nextTheme = currentTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = nextTheme;
      localStorage.setItem('salvadindin-theme', nextTheme);
      syncThemeButtons();
    });

    syncThemeButtons();
  }

  if (!sidebar && !document.querySelector('[data-theme-toggle]') && !document.body.classList.contains('auth-page')) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'theme-toggle theme-toggle-floating';
    button.dataset.themeToggle = 'true';
    document.body.appendChild(button);

    button.addEventListener('click', () => {
      const nextTheme = currentTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = nextTheme;
      localStorage.setItem('salvadindin-theme', nextTheme);
      syncThemeButtons();
    });

    syncThemeButtons();
  }

  const typeSelect = document.querySelector('[data-category-filter]');
  const categorySelect = document.querySelector('[data-category-select]');

  if (typeSelect && categorySelect) {
    const syncCategories = () => {
      const selectedType = typeSelect.value;

      Array.from(categorySelect.options).forEach((option) => {
        const optionType = option.dataset.type;
        const isVisible = !optionType || optionType === selectedType;
        option.hidden = !isVisible;
        option.disabled = !isVisible;
      });

      if (categorySelect.selectedOptions[0]?.disabled) {
        categorySelect.value = '';
      }
    };

    typeSelect.addEventListener('change', syncCategories);
    syncCategories();
  }

  document.querySelectorAll('.alert').forEach((alert) => {
    window.setTimeout(() => {
      alert.classList.add('is-fading');
    }, 4500);
  });

  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = button.closest('.input-shell')?.querySelector('input[type="password"], input[type="text"]');

    if (!input) {
      return;
    }

    button.addEventListener('click', () => {
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      button.setAttribute('aria-label', isPassword ? 'Ocultar senha' : 'Mostrar senha');
    });
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const message = form.dataset.confirm || 'Confirmar esta acao?';

      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });
});
