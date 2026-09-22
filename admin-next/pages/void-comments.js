const TAG = window.__GRAV_PAGE_TAG || 'grav-void-comments--page';

class VoidCommentsPage extends HTMLElement {
  connectedCallback() {
    const params = new URLSearchParams(window.location.search);
    this.query = String(params.get('q') || '').trim();
    this.route = String(params.get('route') || '').trim();
    this.page = Math.max(1, Number(params.get('page') || 1));
    this.focus = String(params.get('focus') || '').trim();
    this.renderShell();
    this.querySelector('[data-search]').value = this.query;
    this.querySelector('[data-route]').value = this.route;
    this.load();
  }

  api(path = '', options = {}) {
    const prefix = window.__GRAV_API_PREFIX || '/api/v1';
    const token = window.__GRAV_API_TOKEN;
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (token) headers['X-API-Token'] = token;
    return fetch(`${prefix}/void-comments-admin${path}`, { credentials: 'same-origin', ...options, headers })
      .then(async response => {
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || `Errore HTTP ${response.status}`);
        return payload;
      });
  }

  renderShell() {
    this.innerHTML = `<style>
      .vm-page{display:block;padding:1.5rem}
      .vm-page .vm-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
      .vm-page h1{font-size:1.5rem;font-weight:700;margin:0}.vm-page h2{font-size:1.1rem;margin:0 0 .75rem}
      .vm-page .vm-search{display:grid;grid-template-columns:minmax(12rem,1fr) minmax(12rem,1fr) auto auto;gap:.5rem;min-width:min(100%,48rem);flex:1;justify-content:flex-end}
      .vm-page .vm-search input{min-width:0;padding:.5rem .7rem;border:1px solid var(--border,#d1d5db);border-radius:.35rem;background:var(--background,#fff);color:inherit}
      .vm-page .vm-search button,.vm-page .vm-actions button{padding:.5rem .8rem;border:1px solid var(--border,#bbb);border-radius:.35rem;cursor:pointer;background:var(--background,#fff);color:inherit}
      .vm-page .vm-section{margin:0 0 2rem}.vm-page .vm-list{display:grid;gap:1rem}.vm-page .vm-card{padding:1rem;border:1px solid var(--border,#ddd);border-radius:.5rem;background:var(--card,#fff)}
      .vm-page .vm-card.vm-focused{border-color:var(--primary,#2563eb);box-shadow:0 0 0 2px color-mix(in srgb,var(--primary,#2563eb) 25%,transparent)}
      .vm-page .vm-meta{color:var(--muted-foreground,#666);font-size:.85rem;margin:.25rem 0 .5rem}.vm-page .vm-links{display:flex;gap:.8rem;flex-wrap:wrap;margin:.5rem 0}.vm-page .vm-links a{font-size:.9rem}
      .vm-page .vm-actions{display:flex;gap:.5rem;flex-wrap:wrap}.vm-page .approve{background:var(--primary,#2563eb);color:#fff}.vm-page .delete{color:var(--destructive,#b91c1c)}
      .vm-page .vm-status{padding:1rem;border:1px dashed var(--border,#bbb);border-radius:.5rem}.vm-page .vm-status[hidden]{display:none}
      .vm-page .vm-edit{display:grid;gap:.65rem;margin:.75rem 0}.vm-page .vm-edit label{display:grid;gap:.25rem;font-size:.85rem;color:var(--muted-foreground,#666)}
      .vm-page .vm-edit input,.vm-page .vm-edit textarea{box-sizing:border-box;width:100%;padding:.55rem .65rem;border:1px solid var(--border,#bbb);border-radius:.35rem;background:var(--background,#fff);color:inherit;font:inherit}
      .vm-page .vm-edit textarea{min-height:8rem;resize:vertical}.vm-page .vm-save{color:var(--primary,#2563eb)}
      .vm-page .vm-pagination{display:flex;align-items:center;gap:.6rem;margin-top:.8rem;color:var(--muted-foreground,#666);font-size:.9rem}.vm-page .vm-pagination button{padding:.35rem .65rem;border:1px solid var(--border,#bbb);border-radius:.35rem;background:var(--background,#fff);color:inherit;cursor:pointer}.vm-page .vm-pagination button:disabled{cursor:default;opacity:.45}
      @media (max-width: 720px){.vm-page .vm-search{grid-template-columns:1fr 1fr}.vm-page .vm-search button{grid-column:span 1}}
    </style><div class="vm-page"><div class="vm-head"><h1>Commenti</h1><div class="vm-search"><input type="search" data-search placeholder="Cerca in autore, email, testo o pagina" aria-label="Cerca commenti"><input type="text" data-route placeholder="Filtra per pagina, es. /articolo" aria-label="Filtra per pagina"><button type="button" data-search-submit>Cerca</button><button type="button" data-refresh>Aggiorna elenco</button></div></div><div class="vm-status" role="status">Caricamento…</div><section class="vm-section"><h2>In attesa di moderazione</h2><div class="vm-list" data-pending></div><div data-pending-pagination></div></section><section class="vm-section"><h2>Pubblicati</h2><div class="vm-list" data-approved></div><div data-approved-pagination></div></section></div>`;
    this.querySelector('[data-refresh]').addEventListener('click', () => this.load());
    this.querySelector('[data-search-submit]').addEventListener('click', () => this.applyFilters());
    this.querySelector('[data-search]').addEventListener('keydown', event => {
      if (event.key === 'Enter') { event.preventDefault(); this.applyFilters(); }
    });
    this.querySelector('[data-route]').addEventListener('keydown', event => {
      if (event.key === 'Enter') { event.preventDefault(); this.applyFilters(); }
    });
  }

  applyFilters() {
    this.query = String(this.querySelector('[data-search]').value || '').trim();
    this.route = String(this.querySelector('[data-route]').value || '').trim();
    this.page = 1;
    this.focus = '';
    this.load();
  }

  async load() {
    const status = this.querySelector('.vm-status');
    const pendingList = this.querySelector('[data-pending]');
    const approvedList = this.querySelector('[data-approved]');
    status.hidden = false;
    status.textContent = 'Caricamento…';
    pendingList.replaceChildren();
    approvedList.replaceChildren();
    const params = new URLSearchParams({ q: this.query, route: this.route, page: String(this.page), per_page: '20' });
    if (this.focus) params.set('focus', this.focus);
    try {
      const data = await this.api(`?${params.toString()}`);
      const comments = Array.isArray(data.data?.comments) ? data.data.comments : [];
      const approved = Array.isArray(data.data?.approved) ? data.data.approved : [];
      const pendingPagination = data.data?.pagination?.pending || { current: this.page, pages: 1, total: 0 };
      const approvedPagination = data.data?.pagination?.approved || { current: this.page, pages: 1, total: 0 };
      this.page = Math.max(Number(pendingPagination.current || 1), Number(approvedPagination.current || 1));
      this.renderPagination(this.querySelector('[data-pending-pagination]'), pendingPagination, 'In attesa');
      this.renderPagination(this.querySelector('[data-approved-pagination]'), approvedPagination, 'Pubblicati');
      comments.forEach(comment => pendingList.append(this.card(comment, 'pending')));
      approved.forEach(comment => approvedList.append(this.card(comment, 'approved')));
      const total = Number(pendingPagination.total || 0) + Number(approvedPagination.total || 0);
      if (!comments.length && !approved.length && total === 0) {
        status.textContent = this.query || this.route ? 'Nessun commento corrisponde ai filtri.' : 'Nessun commento da mostrare.';
        return;
      }
      status.hidden = true;
      const focused = this.querySelector('.vm-focused');
      if (focused) focused.scrollIntoView({ block: 'center' });
      this.focus = '';
    } catch (error) { status.textContent = error.message; }
  }

  renderPagination(container, meta, label) {
    container.replaceChildren();
    const pages = Number(meta.pages || 1);
    if (pages <= 1) return;
    const nav = document.createElement('nav');
    nav.className = 'vm-pagination';
    nav.setAttribute('aria-label', `Paginazione ${label}`);
    const previous = document.createElement('button');
    previous.type = 'button'; previous.textContent = '‹ Precedenti'; previous.disabled = Number(meta.current || 1) <= 1;
    previous.addEventListener('click', () => { this.page = Number(meta.current || 1) - 1; this.load(); });
    const next = document.createElement('button');
    next.type = 'button'; next.textContent = 'Successivi ›'; next.disabled = Number(meta.current || 1) >= pages;
    next.addEventListener('click', () => { this.page = Number(meta.current || 1) + 1; this.load(); });
    const summary = document.createElement('span');
    summary.textContent = `Pagina ${meta.current} di ${pages} · ${meta.total} commenti`;
    nav.append(previous, summary, next);
    container.append(nav);
  }

  field(label, type, value) {
    const wrapper = document.createElement('label');
    wrapper.textContent = label;
    const control = document.createElement(type === 'textarea' ? 'textarea' : 'input');
    if (type !== 'textarea') control.type = type;
    control.value = value || '';
    wrapper.append(control);
    return { wrapper, control };
  }

  card(comment, state = 'pending') {
    const card = document.createElement('article');
    card.className = 'vm-card';
    if (comment.id === this.focus) card.classList.add('vm-focused');
    const title = document.createElement('strong');
    title.textContent = comment.author || 'Anonimo';
    const meta = document.createElement('div');
    meta.className = 'vm-meta';
    meta.textContent = `${comment.created_at || ''} · ${comment.route || ''} · ${comment.email || ''}`;
    const links = document.createElement('div');
    links.className = 'vm-links';
    const publicLink = document.createElement('a');
    publicLink.href = state === 'approved' ? (comment.public_url || comment.route || '#') : (comment.route || '#');
    publicLink.target = '_blank'; publicLink.rel = 'noopener';
    publicLink.textContent = state === 'approved' ? 'Vedi pubblicato' : 'Apri pagina padre';
    links.append(publicLink);
    const form = document.createElement('form');
    form.className = 'vm-edit';
    form.addEventListener('submit', event => { event.preventDefault(); this.save(comment, state, fields); });
    const author = this.field('Nome', 'text', comment.author);
    const email = this.field('Email', 'email', comment.email);
    const body = this.field('Testo', 'textarea', comment.body);
    const fields = { author, email, body };
    const actions = document.createElement('div');
    actions.className = 'vm-actions';
    const save = document.createElement('button');
    save.type = 'submit'; save.className = 'vm-save'; save.textContent = 'Salva modifiche';
    actions.append(save);
    if (state === 'pending') {
      const approve = document.createElement('button');
      approve.type = 'button'; approve.className = 'approve'; approve.textContent = 'Approva';
      approve.addEventListener('click', () => this.moderate('approve', comment.id));
      actions.append(approve);
    }
    const remove = document.createElement('button');
    remove.type = 'button'; remove.className = 'delete'; remove.textContent = 'Elimina definitivamente';
    remove.addEventListener('click', () => { if (confirm('Eliminare definitivamente questo commento?')) this.moderate(state === 'approved' ? 'delete-approved' : 'delete', comment.id); });
    actions.append(remove);
    form.append(author.wrapper, email.wrapper, body.wrapper, actions);
    card.append(title, meta, links, form);
    return card;
  }

  async save(comment, state, fields) {
    try {
      await this.api('/update', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: comment.id, status: state, author: fields.author.control.value, email: fields.email.control.value, body: fields.body.control.value }) });
      await this.load();
    } catch (error) { const status = this.querySelector('.vm-status'); status.hidden = false; status.textContent = error.message; }
  }

  async moderate(action, id) {
    try { await this.api(`/${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) }); await this.load(); }
    catch (error) { const status = this.querySelector('.vm-status'); status.hidden = false; status.textContent = error.message; }
  }
}

if (!customElements.get(TAG)) customElements.define(TAG, VoidCommentsPage);
