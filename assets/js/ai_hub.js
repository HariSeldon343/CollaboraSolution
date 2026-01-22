class AiHub {
  constructor() {
    this.apiBase = window.CN_API_BASE || '/CollaboraNexio/api/';
    if (!this.apiBase.endsWith('/')) this.apiBase += '/';
    this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    this.providers = [];
    this.messages = [];
    this.knowledgeFolders = [];
    this.activeKnowledgeFolderId = 0;

    // Optional deep-link context (e.g. ai.php?tenant_id=...&file_id=...)
    this.ctx = { tenant_id: 0, file_id: 0, artifact_id: 0, context: '' };
    try {
      const url = new URL(window.location.href);
      this.ctx.tenant_id = parseInt(url.searchParams.get('tenant_id') || '0', 10) || 0;
      this.ctx.file_id = parseInt(url.searchParams.get('file_id') || '0', 10) || 0;
      this.ctx.artifact_id = parseInt(url.searchParams.get('artifact_id') || '0', 10) || 0;
      this.ctx.context = String(url.searchParams.get('context') || '').trim();
    } catch (_) {}

    this.el = {
      provider: document.getElementById('aiProviderSelect'),
      model: document.getElementById('aiModelInput'),
      knowledgeFolder: document.getElementById('aiKnowledgeFolderSelect'),
      useKnowledge: document.getElementById('aiUseKnowledge'),
      openKnowledgeFolderBtn: document.getElementById('aiOpenKnowledgeFolderBtn'),
      indexBtn: document.getElementById('aiIndexBtn'),
      knowledgeTestInput: document.getElementById('aiKnowledgeTestInput'),
      knowledgeTestBtn: document.getElementById('aiKnowledgeTestBtn'),
      knowledgeTestResults: document.getElementById('aiKnowledgeTestResults'),
      messages: document.getElementById('aiMessages'),
      input: document.getElementById('aiInput'),
      sendBtn: document.getElementById('aiSendBtn'),
      warn: document.getElementById('aiWarning'),
      form: document.getElementById('aiChatForm'),
    };
  }

  escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  showWarn(text) {
    if (!this.el.warn) return;
    const t = String(text || '').trim();
    if (!t) {
      this.el.warn.style.display = 'none';
      this.el.warn.textContent = '';
      return;
    }
    this.el.warn.style.display = 'block';
    this.el.warn.textContent = t;
  }

  async apiFetch(path, opts = {}) {
    const url = this.apiBase + path.replace(/^\//, '');
    const headers = { ...(opts.headers || {}) };
    if (this.csrfToken) headers['X-CSRF-Token'] = this.csrfToken;
    if (opts.json !== false) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, { ...opts, headers });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_) {}
    if (!res.ok) {
      const msg = (json && (json.error || json.message)) ? (json.error || json.message) : (`HTTP ${res.status}`);
      throw new Error(msg);
    }
    if (!json) throw new Error('Risposta non valida (non-JSON)');
    if (json.success === false) throw new Error(json.error || json.message || 'Errore');
    return json;
  }

  render() {
    if (!this.el.messages) return;
    const me = this;
    this.el.messages.innerHTML = this.messages.map(m => {
      const role = m.role === 'user' ? 'user' : 'assistant';
      const avatar = role === 'user' ? 'TU' : 'AI';
      const citations = Array.isArray(m.citations) && m.citations.length ? m.citations : [];
      const citationsHtml = citations.length ? `
        <div class="ai-hub-citations">
          Fonti: ${citations.map(c => {
            const fid = parseInt(c.file_id || 0, 10) || 0;
            const label = me.escapeHtml(c.logical_path || c.file_name || ('File #' + fid));
            if (!fid) return `<span>${label}</span>`;
            return `<a href="files.php?open_file_id=${fid}&open_mode=view" target="_blank" rel="noopener">${label}</a>`;
          }).join(' · ')}
        </div>` : '';
      return `
        <div class="ai-hub-msg ${role}">
          <div class="ai-hub-avatar">${avatar}</div>
          <div class="ai-hub-bubble">${me.escapeHtml(m.content || '')}${citationsHtml}</div>
        </div>
      `;
    }).join('');
    this.el.messages.scrollTop = this.el.messages.scrollHeight;
  }

  addMessage(role, content, extra = {}) {
    this.messages.push({ role, content, ...extra });
    if (this.messages.length > 40) this.messages = this.messages.slice(-40);
    this.render();
  }

  getSelectedProvider() {
    return String(this.el.provider?.value || 'openai').trim() || 'openai';
  }

  getSelectedModel() {
    return String(this.el.model?.value || '').trim();
  }

  isKnowledgeEnabled() {
    return !!this.el.useKnowledge?.checked;
  }

  getSelectedKnowledgeFolderId() {
    const v = parseInt(String(this.el.knowledgeFolder?.value || '0'), 10) || 0;
    return v > 0 ? v : 0;
  }

  getSelectedKnowledgeFolderName() {
    const fid = this.getSelectedKnowledgeFolderId();
    const found = this.knowledgeFolders.find(x => (parseInt(String(x.folder_id || 0), 10) || 0) === fid) || null;
    return String(found?.folder_name || '').trim();
  }

  openSelectedKnowledgeFolder() {
    const fid = this.getSelectedKnowledgeFolderId();
    if (!fid) return;
    try {
      window.open(`files.php?open_folder_id=${encodeURIComponent(String(fid))}&context=ai_knowledge`, '_blank', 'noopener');
    } catch (_) {}
  }

  async loadProviders() {
    try {
      const res = await this.apiFetch('ai/providers.php?action=list', { method: 'GET', json: false });
      const providers = Array.isArray(res.data?.providers) ? res.data.providers : [];
      this.providers = providers;
      if (this.el.provider) {
        this.el.provider.innerHTML = providers.map(p => {
          const id = String(p.provider || '').trim();
          const label = String(p.label || id).trim();
          return `<option value="${this.escapeHtml(id)}">${this.escapeHtml(label)}</option>`;
        }).join('') || '<option value="openai">OpenAI</option>';
      }
      // Set default model for first provider
      const currentProv = this.getSelectedProvider();
      const p = providers.find(x => String(x.provider) === currentProv) || providers[0] || null;
      if (p && this.el.model && !this.el.model.value) {
        this.el.model.value = String(p.default_model || '');
      }
    } catch (e) {
      // fallback
      if (this.el.provider) this.el.provider.innerHTML = '<option value="openai">OpenAI</option><option value="perplexity">Perplexity</option>';
      if (this.el.model && !this.el.model.value) this.el.model.value = 'gpt-5.2';
    }
  }

  async sendCurrentMessage() {
    const text = String(this.el.input?.value || '').trim();
    if (!text) return;
    this.el.input.value = '';
    this.showWarn('');
    this.addMessage('user', text);

    try {
      const payload = {
        provider: this.getSelectedProvider(),
        model: this.getSelectedModel(),
        messages: this.messages.map(m => ({ role: m.role, content: m.content })),
        options: { use_knowledge: this.isKnowledgeEnabled() },
      };
      if (this.ctx.tenant_id > 0) payload.tenant_id = this.ctx.tenant_id;
      if (this.ctx.file_id > 0) payload.file_id = this.ctx.file_id;
      if (this.ctx.artifact_id > 0) payload.artifact_id = this.ctx.artifact_id;
      const res = await this.apiFetch('ai/chat.php', { method: 'POST', body: JSON.stringify(payload) });
      const answer = String(res.data?.answer_text || '').trim();
      const citations = Array.isArray(res.data?.citations) ? res.data.citations : [];
      const warn = String(res.data?.knowledge_warning || '').trim();
      if (warn) this.showWarn(warn);
      this.addMessage('assistant', answer || '(risposta vuota)', { citations });
    } catch (e) {
      this.showWarn(String(e?.message || e));
      this.addMessage('assistant', 'Errore AI. Controlla configurazione chiavi e indicizzazione knowledge.');
    }
  }

  renderKnowledgeTestResults(results, warningText = '') {
    if (!this.el.knowledgeTestResults) return;
    const arr = Array.isArray(results) ? results : [];
    const warn = String(warningText || '').trim();
    if (!arr.length && !warn) {
      this.el.knowledgeTestResults.style.display = 'none';
      this.el.knowledgeTestResults.innerHTML = '';
      return;
    }

    const listHtml = arr.length ? `
      <ul>
        ${arr.map(r => {
          const fid = parseInt(r.file_id || 0, 10) || 0;
          const label = this.escapeHtml(String(r.logical_path || r.file_name || ('File #' + fid)));
          const snippet = this.escapeHtml(String(r.snippet || ''));
          const link = fid ? `<a href="files.php?open_file_id=${fid}&open_mode=view" target="_blank" rel="noopener">${label}</a>` : `<span>${label}</span>`;
          return `<li>${link}<div style="margin-top:4px; opacity:.9;">${snippet}</div></li>`;
        }).join('')}
      </ul>
    ` : '';

    const warnHtml = warn ? `<div style="margin-bottom:10px; color: var(--color-gray-700);">${this.escapeHtml(warn)}</div>` : '';
    this.el.knowledgeTestResults.style.display = 'block';
    this.el.knowledgeTestResults.innerHTML = `${warnHtml}${listHtml || '<div class="ai-hub-muted">Nessun risultato</div>'}`;
  }

  async runKnowledgeTestSearch() {
    const q = String(this.el.knowledgeTestInput?.value || '').trim();
    if (q.length < 2) {
      this.renderKnowledgeTestResults([], 'Inserisci almeno 2 caratteri per cercare nelle fonti.');
      return;
    }
    try {
      const qs = new URLSearchParams();
      qs.set('q', q);
      qs.set('limit', '6');
      if (this.ctx.tenant_id > 0) qs.set('tenant_id', String(this.ctx.tenant_id));
      const res = await this.apiFetch(`ai/knowledge_search.php?${qs.toString()}`, { method: 'GET', json: false });
      const results = Array.isArray(res.data?.results) ? res.data.results : [];
      const warning = String(res.data?.warning || '').trim();
      this.renderKnowledgeTestResults(results, warning);
    } catch (e) {
      this.renderKnowledgeTestResults([], String(e?.message || e));
    }
  }

  async loadIndexStatus() {
    try {
      const qs = this.ctx.tenant_id > 0 ? `&tenant_id=${encodeURIComponent(String(this.ctx.tenant_id))}` : '';
      const res = await this.apiFetch(`ai/knowledge_index.php?action=status${qs}`, { method: 'GET', json: false });
      const d = res.data || {};

      // Populate folder selector (only when multiple options exist)
      this.knowledgeFolders = Array.isArray(d.available_folders) ? d.available_folders : [];
      const recName = String(d.recommended_folder_name || '').trim();
      if (this.el.knowledgeFolder) {
        if (this.knowledgeFolders.length >= 2) {
          this.el.knowledgeFolder.style.display = '';
          this.el.knowledgeFolder.innerHTML = this.knowledgeFolders.map(f => {
            const id = parseInt(f.folder_id || 0, 10) || 0;
            const name = String(f.folder_name || '').trim();
            return `<option value="${this.escapeHtml(String(id))}">${this.escapeHtml(name || ('#' + id))}</option>`;
          }).join('');

          // Select active folder if known, else recommended, else first
          const activeId = parseInt(String(d.folder_id || '0'), 10) || 0;
          let pickId = activeId;
          if (!pickId && recName) {
            const rf = this.knowledgeFolders.find(x => String(x.folder_name || '').toLowerCase() === recName.toLowerCase());
            pickId = parseInt(String(rf?.folder_id || 0), 10) || 0;
          }
          if (!pickId && this.knowledgeFolders.length) pickId = parseInt(String(this.knowledgeFolders[0].folder_id || 0), 10) || 0;
          if (pickId) this.el.knowledgeFolder.value = String(pickId);
        } else if (this.knowledgeFolders.length === 1) {
          // Keep a hidden single-option selector so we can still resolve folder name/id for actions
          const only = this.knowledgeFolders[0] || {};
          const id = parseInt(only.folder_id || 0, 10) || 0;
          const name = String(only.folder_name || '').trim();
          this.el.knowledgeFolder.innerHTML = `<option value="${this.escapeHtml(String(id))}">${this.escapeHtml(name || ('#' + id))}</option>`;
          if (id) this.el.knowledgeFolder.value = String(id);
          this.el.knowledgeFolder.style.display = 'none';
        } else {
          this.el.knowledgeFolder.style.display = 'none';
          this.el.knowledgeFolder.innerHTML = '';
        }
      }

      // Open folder button (works once files.php supports open_folder_id)
      if (this.el.openKnowledgeFolderBtn) {
        const canOpen = this.knowledgeFolders.length > 0;
        this.el.openKnowledgeFolderBtn.style.display = canOpen ? '' : 'none';
      }

      if (d.storage_available === false) {
        const missing = Array.isArray(d.missing) ? d.missing.join(', ') : '';
        const mig = String(d.migration || '');
        this.showWarn(`Knowledge non disponibile: migrazione mancante${missing ? ` (${missing})` : ''}${mig ? ` — ${mig}` : ''}`);
        return;
      }
      if (!this.knowledgeFolders.length) {
        this.showWarn('Knowledge non configurata: crea /Knowledge o /IMS e avvia indicizzazione.');
        return;
      }

      const folder = String(d.folder_name || recName || 'Knowledge');
      const last = String(d.last_indexed_at || '').trim();
      const files = Number(d.counts?.files ?? 0) || 0;
      const chunks = Number(d.counts?.chunks ?? 0) || 0;
      const err = String(d.last_error || '').trim();
      if (err) {
        this.showWarn(`Knowledge: ultimo stato con errore (${folder}) — ${err}`);
        return;
      }
      if (last) {
        this.showWarn(`Knowledge pronta (${folder}): indicizzata il ${last} — file=${files}, chunk=${chunks}`);
      } else {
        this.showWarn(`Knowledge pronta per l'uso (${folder}) ma non ancora indicizzata: premi “Indicizza knowledge”.`);
      }
    } catch (_) {}
  }

  async runIndex() {
    this.showWarn('Indicizzazione in corso…');
    try {
      const body = this.ctx.tenant_id > 0 ? { tenant_id: this.ctx.tenant_id } : {};
      const selectedFolderName = this.getSelectedKnowledgeFolderName();
      if (selectedFolderName) body.folder_name = selectedFolderName;
      const res = await this.apiFetch('ai/knowledge_index.php?action=run', {
        method: 'POST',
        body: JSON.stringify(body),
      });
      const scanned = res.data?.scanned_files ?? 0;
      const idxF = res.data?.indexed_files ?? 0;
      const idxC = res.data?.indexed_chunks ?? 0;
      const folderNameRes = String(res.data?.folder_name || '');
      const warnings = Array.isArray(res.data?.warnings) ? res.data.warnings : [];
      const msg = `Indicizzazione completata (${folderNameRes || 'Knowledge'}): file=${scanned}, aggiornati=${idxF}, chunk=${idxC}` + (warnings.length ? ` — Note: ${warnings.join(' | ')}` : '');
      this.showWarn(msg);
      await this.loadIndexStatus();
    } catch (e) {
      this.showWarn(String(e?.message || e));
    }
  }

  bind() {
    this.el.form?.addEventListener('submit', (ev) => {
      ev.preventDefault();
      this.sendCurrentMessage();
    });
    this.el.sendBtn?.addEventListener('click', () => this.sendCurrentMessage());
    this.el.provider?.addEventListener('change', () => {
      const p = this.providers.find(x => String(x.provider) === this.getSelectedProvider()) || null;
      if (p && this.el.model) this.el.model.value = String(p.default_model || '');
    });
    this.el.indexBtn?.addEventListener('click', () => this.runIndex());
    this.el.openKnowledgeFolderBtn?.addEventListener('click', () => this.openSelectedKnowledgeFolder());
    this.el.knowledgeTestBtn?.addEventListener('click', () => this.runKnowledgeTestSearch());
    this.el.knowledgeTestInput?.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        this.runKnowledgeTestSearch();
      }
    });
  }

  async init() {
    await this.loadProviders();
    this.bind();
    await this.loadIndexStatus();
    const ctxBits = [];
    if (this.ctx.context) ctxBits.push(`contesto=${this.ctx.context}`);
    if (this.ctx.artifact_id) ctxBits.push(`artifact_id=${this.ctx.artifact_id}`);
    if (this.ctx.file_id) ctxBits.push(`file_id=${this.ctx.file_id}`);
    const ctxLine = ctxBits.length ? (`\n(Deep-link: ${ctxBits.join(', ')})`) : '';
    this.addMessage('assistant', 'Ciao! Se vuoi risposte precise per questo tenant, indicizza prima la cartella /Knowledge o /IMS.' + ctxLine);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const app = new AiHub();
  app.init();
  window.aiHub = app;
});

