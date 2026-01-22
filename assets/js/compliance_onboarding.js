class ComplianceOnboardingAssistant {
  constructor() {
    this.csrfToken = document.getElementById('csrfToken')?.value || '';
    this.apiBase = window.CN_API_BASE || '/CollaboraNexio/api/';
    if (!this.apiBase.endsWith('/')) this.apiBase += '/';
    this.currentTenantId = parseInt(document.getElementById('currentTenantId')?.value || '0', 10) || 0;

    this.sessionId = 0;
    this.messages = [];
    this.checklistId = 0;
    this.items = [];
    this.templates = [];
    this.docProfile = null;
    this.suggestedActions = [];
  }

  init() {
    document.getElementById('complianceOpenAiOnboardingBtn')?.addEventListener('click', () => this.openModal());
    document.getElementById('complianceAiReindexBtn')?.addEventListener('click', () => this.reindexDocs());
    document.getElementById('complianceAiAnalyzeBtn')?.addEventListener('click', () => this.analyzeDocs());
    document.getElementById('complianceAiChecklistLoadBtn')?.addEventListener('click', () => this.ensureChecklist());
    document.getElementById('complianceAiChecklistExportBtn')?.addEventListener('click', () => this.exportChecklist());
    document.getElementById('complianceAiChatSendBtn')?.addEventListener('click', () => this.sendMessage());
    document.getElementById('complianceAiApplyActionsBtn')?.addEventListener('click', () => this.applySuggestedActions());

    this.loadTemplates();
    this.refreshIndexStatus();
  }

  async apiFetch(path, opts = {}) {
    const url = this.apiBase + path.replace(/^\//, '');
    const headers = { ...(opts.headers || {}) };
    if (this.csrfToken) headers['X-CSRF-Token'] = this.csrfToken;
    if (opts.json !== false) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, { ...opts, headers });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (e) {}
    if (!res.ok) {
      const msg = (json && (json.error || json.message)) ? (json.error || json.message) : (`HTTP ${res.status}`);
      throw new Error(msg);
    }
    if (!json) throw new Error('Risposta non valida (non-JSON)');
    if (json.success === false) throw new Error(json.error || json.message || 'Errore');
    return json;
  }

  escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));
  }

  openModal() {
    const modal = document.getElementById('complianceAiOnboardingModal');
    if (modal) modal.style.display = 'block';
    this.renderChat();
    this.renderChecklist();
    this.refreshIndexStatus();
  }

  getActiveProgramId() {
    const sel = document.getElementById('complianceProgramSelect');
    return parseInt(sel?.value || '0', 10) || 0;
  }

  async refreshIndexStatus() {
    if (!this.currentTenantId) return;
    try {
      const res = await this.apiFetch(`client_docs_snapshot.php?tenant_id=${this.currentTenantId}`, { method: 'GET', json: false });
      const data = res.data || {};
      const status = document.getElementById('complianceAiIndexStatus');
      if (status) {
        const last = data.last_indexed_at || 'mai';
        const stale = data.stale ? 'stale' : 'fresh';
        status.textContent = `Indicizzazione: ${stale} · Ultimo: ${last}`;
      }
    } catch (e) {
      const status = document.getElementById('complianceAiIndexStatus');
      if (status) status.textContent = `Indicizzazione non disponibile: ${String(e?.message || e)}`;
    }
  }

  async reindexDocs() {
    try {
      await this.apiFetch('client_docs_reindex.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ tenant_id: this.currentTenantId, force: true }),
      });
      await this.refreshIndexStatus();
    } catch (e) {
      alert(String(e?.message || e));
    }
  }

  async analyzeDocs() {
    try {
      const res = await this.apiFetch('client_docs_analyze.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ tenant_id: this.currentTenantId }),
      });
      this.docProfile = res?.data || null;
      this.renderDocSummary();
    } catch (e) {
      alert(String(e?.message || e));
    }
  }

  renderDocSummary() {
    const box = document.getElementById('complianceAiDocSummary');
    if (!box) return;
    if (!this.docProfile) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }
    const m = this.docProfile.maturity_suggested || 'n/d';
    const c = this.docProfile.confidence || 0;
    box.style.display = 'block';
    box.innerHTML = `<div><strong>Maturità:</strong> ${this.escapeHtml(m)} · <strong>Confidenza:</strong> ${this.escapeHtml(c)}</div>`;
  }

  async loadTemplates() {
    try {
      const res = await this.apiFetch('checklist_templates.php', { method: 'GET', json: false });
      this.templates = Array.isArray(res.data?.templates) ? res.data.templates : [];
      const sel = document.getElementById('complianceAiChecklistTemplate');
      if (sel) {
        sel.innerHTML = this.templates.map(t => `<option value="${this.escapeHtml(t.template_key)}">${this.escapeHtml(t.title || t.template_key)}</option>`).join('');
      }
    } catch (_) {}
  }

  async ensureChecklist() {
    const tpl = document.getElementById('complianceAiChecklistTemplate')?.value || '';
    if (!tpl) return;
    const programId = this.getActiveProgramId();
    try {
      const res = await this.apiFetch('checklist_get_or_create.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          tenant_id: this.currentTenantId,
          template_key: tpl,
          scope_type: 'program',
          scope_id: programId || null,
        }),
      });
      this.checklistId = parseInt(res?.data?.checklist_id || '0', 10) || 0;
      await this.loadChecklistItems();
    } catch (e) {
      alert(String(e?.message || e));
    }
  }

  async loadChecklistItems() {
    if (!this.checklistId) return;
    const res = await this.apiFetch(`checklist_get.php?checklist_id=${this.checklistId}&tenant_id=${this.currentTenantId}`, { method: 'GET', json: false });
    this.items = Array.isArray(res?.data?.items) ? res.data.items : [];
    this.renderChecklist();
  }

  renderChecklist() {
    const wrap = document.getElementById('complianceAiChecklistTable');
    if (!wrap) return;
    if (!this.items.length) {
      wrap.innerHTML = '<div class="compliance-muted">Checklist non caricata.</div>';
      return;
    }
    const statusOptions = ['missing','present','to_review','done','not_applicable'];
    wrap.innerHTML = `
      <table class="compliance-table">
        <thead>
          <tr>
            <th>Sezione</th>
            <th>Item</th>
            <th>Obblig.</th>
            <th>Stato</th>
            <th>Note</th>
            <th>Evidenze</th>
          </tr>
        </thead>
        <tbody>
          ${this.items.map(it => {
            const ev = it.evidences_json ? (() => {
              try { const d = JSON.parse(it.evidences_json); return Array.isArray(d) ? d : []; } catch (_) { return []; }
            })() : [];
            return `
              <tr>
                <td>${this.escapeHtml(it.section_key || '')}</td>
                <td>
                  <div style="font-weight:700;">${this.escapeHtml(it.title || '')}</div>
                  <div class="compliance-muted">${this.escapeHtml(it.description || '')}</div>
                </td>
                <td>${it.required_flag ? 'SI' : 'NO'}</td>
                <td>
                  <select class="compliance-input" data-item-key="${this.escapeHtml(it.item_key)}" data-field="status">
                    ${statusOptions.map(s => `<option value="${s}"${s === (it.status || 'missing') ? ' selected' : ''}>${s}</option>`).join('')}
                  </select>
                </td>
                <td><textarea class="compliance-input" rows="2" data-item-key="${this.escapeHtml(it.item_key)}" data-field="answer_text">${this.escapeHtml(it.answer_text || '')}</textarea></td>
                <td>
                  <div class="compliance-muted">${ev.map(e => `#${e.file_id}`).join(', ') || '—'}</div>
                  <button type="button" class="btn btn-secondary btn-sm" data-item-key="${this.escapeHtml(it.item_key)}" data-action="add-evidence">Collega file</button>
                </td>
              </tr>
            `;
          }).join('')}
        </tbody>
      </table>
    `;

    wrap.querySelectorAll('[data-field]').forEach(el => {
      el.addEventListener('change', () => this.updateItem(el));
    });
    wrap.querySelectorAll('[data-action="add-evidence"]').forEach(btn => {
      btn.addEventListener('click', () => this.addEvidence(btn.getAttribute('data-item-key')));
    });
  }

  async updateItem(el) {
    const itemKey = el.getAttribute('data-item-key');
    const field = el.getAttribute('data-field');
    if (!itemKey || !field || !this.checklistId) return;
    const payload = { checklist_id: this.checklistId, item_key: itemKey };
    if (field === 'status') payload.status = el.value;
    if (field === 'answer_text') payload.answer_text = el.value;
    try {
      await this.apiFetch('checklist_item_upsert.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify(payload),
      });
      await this.loadChecklistItems();
    } catch (e) {
      alert(String(e?.message || e));
    }
  }

  async addEvidence(itemKey) {
    if (!this.checklistId || !itemKey) return;
    const raw = String(prompt('Inserisci file_id:', '') || '').trim();
    const fid = parseInt(raw, 10) || 0;
    if (!fid) return;
    const item = this.items.find(x => String(x.item_key) === String(itemKey));
    let ev = [];
    if (item?.evidences_json) {
      try { const d = JSON.parse(item.evidences_json); ev = Array.isArray(d) ? d : []; } catch (_) {}
    }
    ev.push({ file_id: fid });
    await this.apiFetch('checklist_evidence_add.php', {
      method: 'POST',
      json: true,
      body: JSON.stringify({ checklist_id: this.checklistId, item_key: itemKey, file_id: fid }),
    });
    await this.loadChecklistItems();
  }

  async exportChecklist() {
    if (!this.checklistId) return;
    const url = `${this.apiBase}checklist_export_xlsx.php?checklist_id=${this.checklistId}&tenant_id=${this.currentTenantId}`;
    window.open(url, '_blank', 'noopener');
  }

  renderChat() {
    const wrap = document.getElementById('complianceAiChatMessages');
    if (!wrap) return;
    if (!this.messages.length) {
      wrap.innerHTML = '<div class="compliance-muted">Nessun messaggio. Avvia la conversazione.</div>';
      return;
    }
    wrap.innerHTML = this.messages.map(m => `
      <div class="compliance-ai-chat-bubble ${m.role === 'assistant' ? 'assistant' : ''}">
        ${this.escapeHtml(m.content || '')}
      </div>
    `).join('');
    wrap.scrollTop = wrap.scrollHeight + 100;
  }

  async sendMessage() {
    const input = document.getElementById('complianceAiChatInput');
    const text = String(input?.value || '').trim();
    if (!text) return;
    this.messages.push({ role: 'user', content: text });
    if (input) input.value = '';
    this.renderChat();
    try {
      const res = await this.apiFetch('ai/chat.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          mode: 'onboarding',
          tenant_id: this.currentTenantId,
          session_id: this.sessionId || undefined,
          program_id: this.getActiveProgramId() || undefined,
          message: text,
          provider: 'openai',
        }),
      });
      this.sessionId = parseInt(res?.data?.session_id || '0', 10) || this.sessionId;
      const msg = res?.data?.assistant_message || '';
      this.messages.push({ role: 'assistant', content: msg });
      this.renderChat();
      await this.loadChecklistItems();
    } catch (e) {
      alert(String(e?.message || e));
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const assistant = new ComplianceOnboardingAssistant();
  assistant.init();
});

