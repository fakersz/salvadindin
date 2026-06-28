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

  document.querySelectorAll('[data-category-filter]').forEach((typeSelect) => {
    const form = typeSelect.closest('form');
    const categorySelect = form?.querySelector('[data-category-select]');

    if (!categorySelect) {
      return;
    }

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
  });

  document.querySelectorAll('[data-debt-type]').forEach((select) => {
    const form = select.closest('form');
    const monthlyFields = form?.querySelectorAll('[data-monthly-installments], [data-monthly-paid-installments]');
    const installmentFields = form?.querySelectorAll('[data-financing-field]');
    const standardDueDateField = form?.querySelector('[data-standard-due-date]');

    if (!monthlyFields?.length && !installmentFields?.length) {
      return;
    }

    const syncDebtInstallments = () => {
      monthlyFields?.forEach((field) => {
        field.hidden = select.value !== 'monthly';
      });

      installmentFields?.forEach((field) => {
        field.hidden = select.value !== 'installment';
      });

      if (standardDueDateField) {
        standardDueDateField.hidden = select.value === 'installment';
      }
    };

    select.addEventListener('change', syncDebtInstallments);
    syncDebtInstallments();
  });

  document.querySelectorAll('[data-edit-transaction]').forEach((button) => {
    const targetId = button.dataset.target;
    const target = targetId ? document.getElementById(targetId) : null;

    if (!target) {
      return;
    }

    button.addEventListener('click', () => {
      const isOpening = target.hidden;

      document.querySelectorAll('.transaction-edit-row, .transaction-edit-panel').forEach((panel) => {
        panel.hidden = true;
      });

      document.querySelectorAll('[data-edit-transaction]').forEach((editButton) => {
        editButton.textContent = 'Editar';
      });

      target.hidden = !isOpening;
      button.textContent = isOpening ? 'Fechar' : 'Editar';

      if (isOpening && target.classList.contains('transaction-edit-panel')) {
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  });

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

  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const submitterMessage = event.submitter?.dataset.confirm;
      const message = submitterMessage || form.dataset.confirm || 'Confirmar esta acao?';

      if (!submitterMessage && !form.dataset.confirm) {
        return;
      }

      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });
});
