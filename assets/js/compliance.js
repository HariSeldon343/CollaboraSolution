class ComplianceDashboard {
  constructor() {
    this.csrfToken = document.getElementById('csrfToken')?.value || '';
    this.apiBase = window.CN_API_BASE || '/CollaboraNexio/api/';
    if (!this.apiBase.endsWith('/')) this.apiBase += '/';

    this.programs = [];
    this.activeProgramId = null;
    this.activeCoverageStandard = '';

    this.userRole = document.getElementById('userRole')?.value || 'user';
    this.currentUserId = parseInt(document.getElementById('currentUserId')?.value || '0', 10) || 0;

    // Wizard state
    this.wiz = {
      open: false,
      artifact: null, // {id,title,file_id,artifact_key,program_id,standards?}
      template: null, // {input_schema_json, ai_hint, ...}
      schema: null,   // parsed schema object
      inputs: {},     // current inputs object
      profile: {},    // current profile object
      warnings: [],
      isSaving: false,
      isApplying: false,
      isAiBusy: false,
      aiProgressTimer: null,
      aiProgressStartedAt: 0,
      aiProgressExpectedMs: 0,
      aiProgressBaseText: '',
      aiProgressBtnEl: null,
      aiProgressBtnPrevText: '',
      // Chat state (side panel inside wizard modal; non-blocking)
      chat: {
        messages: [], // {role:'user'|'assistant', content:string}
        activeFieldKey: '',
        lastAnswerText: '',
        citations: [],
        knowledgeWarning: '',
        aiWarnings: [],
        collapsed: false,
        panel_open: false,
      },
    };
  }

  init() {
    const sel = document.getElementById('complianceProgramSelect');
    sel?.addEventListener('change', () => {
      const id = parseInt(sel.value || '0', 10) || 0;
      this.activeProgramId = id || null;
      this.refresh();
    });

    document.getElementById('complianceProgramProfileBtn')?.addEventListener('click', () => this.openProfileModal());
    document.getElementById('complianceProfileSaveBtn')?.addEventListener('click', () => this.saveProfileFromModal());

    // Modal close handlers
    document.querySelectorAll('[data-close]')?.forEach(btn => {
      btn.addEventListener('click', () => {
        const id = String(btn.getAttribute('data-close') || '').trim();
        if (id) this.closeModal(id);
      });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.closeModal('complianceWizardModal');
        this.closeModal('complianceProfileModal');
      }
    });

    document.getElementById('complianceWizardSaveBtn')?.addEventListener('click', () => this.saveWizardInputs());
    document.getElementById('complianceWizardApplyBtn')?.addEventListener('click', () => this.applyWizardToDocument());
    document.getElementById('complianceWizardAiDraftBtn')?.addEventListener('click', () => this.generateWizardDraftWithAi());

    const stdSel = document.getElementById('complianceCoverageStandardSelect');
    stdSel?.addEventListener('change', () => {
      this.activeCoverageStandard = String(stdSel.value || '').trim();
      this.refresh();
    });
    document.getElementById('complianceCoverageClauseFilter')?.addEventListener('input', this.debounce(() => this.refresh(), 220));
    document.getElementById('complianceCoverageOnlyUncovered')?.addEventListener('change', () => this.refresh());

    this.loadPrograms();
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

  setStorageWarning(text) {
    const w = document.getElementById('complianceStorageWarning');
    if (!w) return;
    if (!text) {
      w.style.display = 'none';
      w.textContent = '';
      return;
    }
    w.style.display = 'block';
    w.innerHTML = text;
  }

  async loadPrograms() {
    const sel = document.getElementById('complianceProgramSelect');
    if (sel) sel.innerHTML = `<option value="">Caricamento…</option>`;
    try {
      const res = await this.apiFetch('compliance/programs.php?action=list', { method: 'GET', json: false });
      if (res.data?.storage_available === false) {
        this.setStorageWarning(`Storage non disponibile: applica migrazione 41/42 (tools/apply_migration_41_compliance_programs.php, tools/apply_migration_42_compliance_task_links.php).`);
        if (sel) sel.innerHTML = `<option value="">Storage non disponibile</option>`;
        return;
      }
      this.setStorageWarning('');
      this.programs = res.data?.programs || [];
      if (!this.programs.length) {
        if (sel) sel.innerHTML = `<option value="">Nessun programma (chiedi al consulente di provisionare)</option>`;
        return;
      }
      if (sel) {
        sel.innerHTML = `<option value="">Seleziona…</option>` + this.programs.map(p => {
          const stds = Array.isArray(p.program_standards) && p.program_standards.length
            ? p.program_standards.map(s => String(s.code || '').trim()).filter(Boolean)
            : [String(p.standard_code || '').trim()].filter(Boolean);
          const label = stds.length > 1
            ? `IMS: ${stds.join(', ')}`
            : `${p.standard_code} (${p.standard_edition})`;
          return `<option value="${p.id}">${this.escapeHtml(label)}</option>`;
        }).join('');
      }

      // Optional deep-link: auto-select program_id from query string
      try {
        const url = new URL(window.location.href);
        const pid = parseInt(url.searchParams.get('program_id') || '0', 10) || 0;
        if (pid > 0 && this.programs.some(x => (x.id || 0) === pid)) {
          this.activeProgramId = pid;
          if (sel) sel.value = String(pid);
          await this.refresh();
        }
      } catch (_) {}
    } catch (e) {
      this.setStorageWarning(this.escapeHtml(String(e?.message || e)));
      if (sel) sel.innerHTML = `<option value="">Errore</option>`;
    }
  }

  async refresh() {
    const tbody = document.getElementById('complianceArtifactsTbody');
    const covBox = document.getElementById('complianceCoverageBox');
    const kpis = document.getElementById('complianceKpis');
    const stdSel = document.getElementById('complianceCoverageStandardSelect');
    const profileBtn = document.getElementById('complianceProgramProfileBtn');
    if (!this.activeProgramId) {
      if (tbody) tbody.innerHTML = `<tr><td colspan="8" class="compliance-muted">Seleziona un programma…</td></tr>`;
      if (covBox) covBox.textContent = 'Seleziona un programma…';
      if (kpis) kpis.style.display = 'none';
      if (stdSel) { stdSel.disabled = true; stdSel.innerHTML = `<option value="">—</option>`; }
      if (profileBtn) profileBtn.disabled = true;
      return;
    }

    try {
      const [artsRes, covRes] = await Promise.all([
        this.apiFetch(`compliance/artifacts.php?action=list&program_id=${this.activeProgramId}`, { method: 'GET', json: false }),
        this.apiFetch(`compliance/coverage.php?program_id=${this.activeProgramId}${this.activeCoverageStandard ? `&standard_code=${encodeURIComponent(this.activeCoverageStandard)}` : ''}`, { method: 'GET', json: false }),
      ]);

      const artifacts = artsRes.data?.artifacts || [];
      const byStd = Array.isArray(covRes.data?.by_standard) ? covRes.data.by_standard : [];
      const stds = byStd.map(s => String(s.standard_code || '').trim()).filter(Boolean);
      const activeStd = this.activeCoverageStandard && stds.includes(this.activeCoverageStandard) ? this.activeCoverageStandard : (stds[0] || '');
      const activeStdObj = byStd.find(s => String(s.standard_code) === activeStd) || null;
      const totals = activeStdObj?.totals || null;

      // KPIs
      const docCount = artifacts.filter(a => (a.file_id || 0) > 0).length;
      const taskOpen = artifacts.filter(a => a.task_status && a.task_status !== 'done' && a.task_status !== 'cancelled').length;
      if (kpis) kpis.style.display = 'flex';
      const kpiCoverage = document.getElementById('kpiCoverage');
      const kpiDocs = document.getElementById('kpiDocs');
      const kpiOpen = document.getElementById('kpiOpenTasks');
      if (kpiCoverage) kpiCoverage.textContent = totals ? `${totals.coverage_percent}%` : '—';
      if (kpiDocs) kpiDocs.textContent = `${docCount}/${artifacts.length}`;
      if (kpiOpen) kpiOpen.textContent = String(taskOpen);

      if (profileBtn) profileBtn.disabled = false;

      // Artifacts table
      if (tbody) {
        if (!artifacts.length) {
          tbody.innerHTML = `<tr><td colspan="8" class="compliance-muted">Nessun deliverable</td></tr>`;
        } else {
          tbody.innerHTML = artifacts.map(a => {
            const standardsBadges = Array.isArray(a.standards) && a.standards.length
              ? a.standards.map(s => `<span class="compliance-pill">${this.escapeHtml(String(s))}</span>`).join(' ')
              : `<span class="compliance-muted">—</span>`;
            const clauses = Array.isArray(a.clause_refs_flat) ? a.clause_refs_flat.join(', ') : '';
            const docLink = a.file_id ? `<a class="compliance-link" href="files.php?open_file_id=${a.file_id}&open_mode=edit" target="_blank" rel="noopener">Apri</a>` : `<span class="compliance-muted">—</span>`;
            const hasSchema = !!(a.wizard && a.wizard.has_schema);
            const compileBtn = (a.file_id && a.id && hasSchema)
              ? `<button type="button" class="btn btn-secondary btn-sm" data-action="compile" data-artifact-id="${a.id}">Compila</button>`
              : `<span class="compliance-muted">—</span>`;
            const taskLink = a.task_id ? `<a class="compliance-link" href="tasks.php" target="_blank" rel="noopener">#${a.task_id}</a>` : `<span class="compliance-muted">—</span>`;
            const versions = (a.wizard && a.wizard.versions_count) ? parseInt(a.wizard.versions_count, 10) || 0 : 0;
            const inputsAt = (a.wizard && a.wizard.inputs_updated_at) ? String(a.wizard.inputs_updated_at || '') : '';
            let st = 'Da compilare';
            if (!hasSchema) st = '—';
            else if (versions > 0) st = 'Applicato al documento';
            else if (inputsAt) st = 'In compilazione';
            else st = 'Da compilare';
            const ts = a.task_status ? ` (${a.task_status})` : '';

            const artStatus = String(a.status || 'todo').trim();
            const statusOptions = [
              { v: 'todo', label: 'Applicabile' },
              { v: 'draft', label: 'Da valutare' },
              { v: 'in_review', label: 'In revisione' },
              { v: 'approved', label: 'Approvato' },
              { v: 'obsolete', label: 'Non applicabile (N/A)' },
            ];
            const statusSelect = `
              <select data-action="set-artifact-status" data-artifact-id="${this.escapeHtml(String(a.id || ''))}"
                style="margin-top:6px; width:100%; max-width:220px; font-size:12px; padding:4px 8px; border:1px solid var(--compliance-border); border-radius:8px; background:#fff;">
                ${statusOptions.map(o => `<option value="${o.v}" ${o.v === artStatus ? 'selected' : ''}>${this.escapeHtml(o.label)}</option>`).join('')}
              </select>
            `;
            return `
              <tr>
                <td>${this.escapeHtml(a.title || '')}</td>
                <td><span class="compliance-pill">${this.escapeHtml(a.artifact_type || '')}</span></td>
                <td>${standardsBadges}</td>
                <td class="compliance-muted">${this.escapeHtml(clauses)}</td>
                <td>
                  <span class="compliance-pill">${this.escapeHtml(st)}${this.escapeHtml(ts)}</span>
                  ${statusSelect}
                </td>
                <td>${docLink}</td>
                <td>${compileBtn}</td>
                <td>${taskLink}</td>
              </tr>
            `;
          }).join('');

          // bind compile buttons
          tbody.querySelectorAll('[data-action="compile"]').forEach(btn => {
            btn.addEventListener('click', async () => {
              const id = parseInt(String(btn.getAttribute('data-artifact-id') || '0'), 10) || 0;
              if (id > 0) await this.openWizardForArtifact(id, artifacts);
            });
          });

          // bind status selects (applicabilità / lifecycle)
          tbody.querySelectorAll('[data-action="set-artifact-status"]').forEach(sel => {
            sel.addEventListener('change', async () => {
              const id = parseInt(String(sel.getAttribute('data-artifact-id') || '0'), 10) || 0;
              const status = String(sel.value || '').trim();
              if (!id || !status) return;
              try {
                await this.apiFetch('compliance/artifacts.php?action=update', {
                  method: 'POST',
                  json: true,
                  body: JSON.stringify({ artifact_id: id, status }),
                });
                await this.refresh();
              } catch (e) {
                alert('Errore: ' + String(e?.message || e));
              }
            });
          });
        }
      }

      // Coverage view
      if (covBox) {
        // Update standard selector
        if (stdSel) {
          stdSel.disabled = !stds.length;
          stdSel.innerHTML = stds.length
            ? stds.map(s => `<option value="${this.escapeHtml(s)}">${this.escapeHtml(s)}</option>`).join('')
            : `<option value="">—</option>`;
          if (activeStd) stdSel.value = activeStd;
          this.activeCoverageStandard = activeStd;
        }

        const clauseFilter = String(document.getElementById('complianceCoverageClauseFilter')?.value || '').trim();
        const onlyUncovered = !!document.getElementById('complianceCoverageOnlyUncovered')?.checked;
        let covered = activeStdObj?.covered || [];
        let missing = activeStdObj?.missing || [];
        let notApplicable = activeStdObj?.not_applicable || [];

        if (clauseFilter) {
          covered = covered.filter(x => String(x.clause || '').includes(clauseFilter));
          missing = missing.filter(x => String(x.clause || '').includes(clauseFilter));
          notApplicable = notApplicable.filter(x => String(x.clause || '').includes(clauseFilter));
        }
        if (onlyUncovered) {
          covered = [];
          notApplicable = [];
        }

        const catalogAvail = !!activeStdObj?.meta?.catalog_storage_available;
        const head = totals
          ? `<div class="compliance-muted">Norma: <strong>${this.escapeHtml(activeStd || '—')}</strong> — Coperti: <strong>${totals.covered}</strong> / ${this.escapeHtml(String(totals.effective_total ?? totals.total_requirements))} (N/A: ${this.escapeHtml(String(totals.not_applicable ?? 0))}, mancanti: ${this.escapeHtml(String(totals.uncovered ?? 0))})</div>`
          : `<div class="compliance-muted">Norma: <strong>${this.escapeHtml(activeStd || '—')}</strong></div>`;
        const note = catalogAvail ? '' : `<div class="compliance-muted" style="margin-top:6px;">Coverage requisiti non disponibile per questa norma (catalogo mancante).</div>`;
        const list = (arr, label, isMissing = false) => {
          if (!arr.length) return `<div class="compliance-muted">—</div>`;
          return `<div style="margin-top:10px;"><div style="font-weight:700;">${label}</div><ul style="margin:6px 0 0 18px;">${
            arr.slice(0, 50).map(x => {
              const clause = this.escapeHtml(x.clause || '');
              const title = this.escapeHtml(x.requirement?.title || '');
              const createBtn = isMissing
                ? ` <button type="button" class="btn btn-primary btn-sm" style="margin-left:8px; padding:2px 8px; font-size:11px;" data-action="create-artifact" data-clause="${clause}" data-title="${title}" data-standard="${this.escapeHtml(activeStd || '')}">+ Crea</button>`
                : '';
              return `<li style="margin-bottom:6px;"><span class="compliance-pill">${clause}</span> <span class="compliance-muted">${title}</span>${createBtn}</li>`;
            }).join('')
          }</ul></div>`;
        };
        covBox.innerHTML =
          head +
          note +
          list(missing, 'Mancanti', true) +
          (notApplicable && notApplicable.length ? list(notApplicable, 'Non applicabili (N/A)', false) : '') +
          list(covered, 'Coperti', false);

        // Bind create-artifact buttons
        covBox.querySelectorAll('[data-action="create-artifact"]').forEach(btn => {
          btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const clause = String(btn.getAttribute('data-clause') || '').trim();
            const title = String(btn.getAttribute('data-title') || '').trim();
            const standard = String(btn.getAttribute('data-standard') || '').trim();
            if (clause && title) {
              await this.createArtifactFromMissing(clause, title, standard, btn);
            }
          });
        });
      }
    } catch (e) {
      if (tbody) tbody.innerHTML = `<tr><td colspan="8" class="compliance-muted">Errore: ${this.escapeHtml(String(e?.message || e))}</td></tr>`;
      if (covBox) covBox.textContent = `Errore: ${String(e?.message || e)}`;
      if (kpis) kpis.style.display = 'none';
      const profileBtn = document.getElementById('complianceProgramProfileBtn');
      if (profileBtn) profileBtn.disabled = true;
    }
  }

  // ---------------------------
  // Create artifact from missing requirement
  // ---------------------------

  async createArtifactFromMissing(clause, title, standardCode, btnEl = null) {
    if (!this.activeProgramId) return;
    
    const originalText = btnEl ? btnEl.textContent : '';
    if (btnEl) {
      btnEl.disabled = true;
      btnEl.textContent = 'Creazione...';
    }

    try {
      const res = await this.apiFetch('compliance/artifact_create.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          program_id: this.activeProgramId,
          clause: clause,
          standard_code: standardCode || 'ISO9001',
          requirement_title: title,
        }),
      });

      const data = res.data || {};
      const artifactId = data.artifact_id || 0;
      const alreadyExisted = !!data.already_existed;

      if (btnEl) {
        btnEl.textContent = alreadyExisted ? 'Già esistente' : 'Creato!';
        btnEl.classList.remove('btn-primary');
        btnEl.classList.add('btn-secondary');
      }

      // Refresh the artifacts table and coverage view
      await this.refresh();

      // Show success message
      const msg = alreadyExisted
        ? `Deliverable per clausola ${clause} già esistente`
        : `Deliverable "${title}" creato per clausola ${clause}`;
      alert(msg);

    } catch (e) {
      if (btnEl) {
        btnEl.disabled = false;
        btnEl.textContent = originalText;
      }
      alert('Errore: ' + String(e?.message || e));
    }
  }

  // ---------------------------
  // Modal helpers
  // ---------------------------

  openModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'flex';
  }

  closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'none';
    if (id === 'complianceWizardModal') {
      // Best-effort: stop any running AI progress when closing the wizard
      this.stopAiProgress();
      const warningEl = document.getElementById('complianceWizardWarning');
      if (warningEl) { warningEl.style.display = 'none'; warningEl.textContent = ''; }
      // Reset chat state
      this.wiz.chat = { messages: [], activeFieldKey: '', lastAnswerText: '', citations: [], knowledgeWarning: '', aiWarnings: [], collapsed: false, panel_open: false };
      this.closeWizardChatPanel();
    }
  }

  // ---------------------------
  // AI progress helpers (best-effort, non-streaming)
  // ---------------------------

  stopAiProgress() {
    if (this.wiz.aiProgressTimer) {
      try { clearInterval(this.wiz.aiProgressTimer); } catch (_) {}
    }
    this.wiz.aiProgressTimer = null;
    this.wiz.aiProgressStartedAt = 0;
    this.wiz.aiProgressExpectedMs = 0;
    this.wiz.aiProgressBaseText = '';

    const btn = this.wiz.aiProgressBtnEl;
    const prev = this.wiz.aiProgressBtnPrevText;
    if (btn) {
      btn.disabled = false;
      if (prev) btn.textContent = prev;
    }
    this.wiz.aiProgressBtnEl = null;
    this.wiz.aiProgressBtnPrevText = '';
    this.wiz.isAiBusy = false;
  }

  startAiProgress({ wrapEl, baseText = 'AI in corso…', expectedMs = 20000, btnEl = null, btnTextBusy = 'AI…' } = {}) {
    // Clear any previous run first
    this.stopAiProgress();
    this.wiz.isAiBusy = true;

    this.wiz.aiProgressStartedAt = Date.now();
    this.wiz.aiProgressExpectedMs = Math.max(1500, parseInt(String(expectedMs || 0), 10) || 20000);
    this.wiz.aiProgressBaseText = String(baseText || 'AI in corso…');

    if (btnEl) {
      this.wiz.aiProgressBtnEl = btnEl;
      this.wiz.aiProgressBtnPrevText = String(btnEl.textContent || '').trim();
      btnEl.disabled = true;
    }

    const update = () => {
      const startedAt = this.wiz.aiProgressStartedAt || Date.now();
      const elapsed = Math.max(0, Date.now() - startedAt);
      const expected = this.wiz.aiProgressExpectedMs || 20000;
      // Linear progress up to 95%, then hold until completion
      let pct = Math.floor(Math.min(0.95, elapsed / expected) * 100);
      if (pct < 1) pct = 1;
      if (pct > 95) pct = 95;

      const msg = `${this.wiz.aiProgressBaseText} ${pct}%`;
      if (wrapEl) {
        wrapEl.style.display = 'block';
        wrapEl.textContent = msg;
      }
      const btn = this.wiz.aiProgressBtnEl;
      if (btn) {
        btn.textContent = `${btnTextBusy} ${pct}%`;
      }
    };

    update();
    this.wiz.aiProgressTimer = setInterval(update, 250);
  }

  // ---------------------------
  // Profile modal
  // ---------------------------

  profileTextareaToList(text) {
    return String(text || '')
      .split('\n')
      .map(s => s.trim())
      .filter(Boolean);
  }

  profileListToTextarea(arr) {
    return Array.isArray(arr) ? arr.map(s => String(s)).join('\n') : '';
  }

  async openProfileModal() {
    if (!this.activeProgramId) return;
    const msg = document.getElementById('complianceProfileMsg');
    if (msg) { msg.style.display = 'none'; msg.textContent = ''; }

    // load profile
    try {
      const res = await this.apiFetch(`compliance/program_profile.php?action=get&program_id=${this.activeProgramId}`, { method: 'GET', json: false });
      if (res.data?.storage_available === false) {
        this.setStorageWarning('Storage profilo non disponibile: applica migrazione 47.');
        return;
      }
      const p = res.data?.profile || {};
      document.getElementById('complianceProfileCompanyName').value = String(p.company_name || '');
      document.getElementById('complianceProfileSites').value = this.profileListToTextarea(p.sites || []);
      document.getElementById('complianceProfileProducts').value = this.profileListToTextarea(p.products_services || []);
      document.getElementById('complianceProfileProcesses').value = this.profileListToTextarea(p.processes || []);
      document.getElementById('complianceProfileRoles').value = this.profileListToTextarea(p.roles || []);
      document.getElementById('complianceProfileNotes').value = String(p.notes || '');

      this.openModal('complianceProfileModal');
    } catch (e) {
      this.setStorageWarning(this.escapeHtml(String(e?.message || e)));
    }
  }

  async saveProfileFromModal() {
    if (!this.activeProgramId) return;
    const msg = document.getElementById('complianceProfileMsg');
    const show = (t) => {
      if (!msg) return;
      msg.style.display = 'block';
      msg.textContent = String(t || '');
    };

    const profile = {
      company_name: String(document.getElementById('complianceProfileCompanyName')?.value || '').trim(),
      sites: this.profileTextareaToList(document.getElementById('complianceProfileSites')?.value || ''),
      products_services: this.profileTextareaToList(document.getElementById('complianceProfileProducts')?.value || ''),
      processes: this.profileTextareaToList(document.getElementById('complianceProfileProcesses')?.value || ''),
      roles: this.profileTextareaToList(document.getElementById('complianceProfileRoles')?.value || ''),
      notes: String(document.getElementById('complianceProfileNotes')?.value || '').trim(),
    };

    try {
      await this.apiFetch('compliance/program_profile.php?action=save', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ program_id: this.activeProgramId, profile })
      });
      show('Salvato');
      setTimeout(() => this.closeModal('complianceProfileModal'), 500);
    } catch (e) {
      show(String(e?.message || e));
    }
  }

  // ---------------------------
  // Wizard modal (per deliverable)
  // ---------------------------

  async openWizardForArtifact(artifactId, artifactsList) {
    const art = (Array.isArray(artifactsList) ? artifactsList : []).find(x => (x.id || 0) === artifactId) || null;
    if (!art) return;
    if (!art.file_id) return;

    this.wiz.artifact = art;
    this.wiz.warnings = [];

    const titleEl = document.getElementById('complianceWizardTitle');
    if (titleEl) titleEl.textContent = `Compilazione guidata — ${String(art.title || '')}`;

    const metaEl = document.getElementById('complianceWizardMeta');
    if (metaEl) {
      const standards = Array.isArray(art.standards) ? art.standards.join(', ') : '';
      metaEl.innerHTML = `Documento: <strong>${this.escapeHtml(String(art.title || ''))}</strong> — <a class="compliance-link" href="files.php?open_file_id=${art.file_id}&open_mode=edit" target="_blank" rel="noopener">Apri in OnlyOffice</a>`
        + ` — <a class="compliance-link" href="ai.php?artifact_id=${encodeURIComponent(String(art.id || ''))}&file_id=${encodeURIComponent(String(art.file_id || ''))}&context=compliance" target="_blank" rel="noopener">Apri in AI</a>`
        + (standards ? ` <span class="compliance-small">(Norme: ${this.escapeHtml(standards)})</span>` : '');
    }

    // Load schema + profile + inputs
    const warningEl = document.getElementById('complianceWizardWarning');
    if (warningEl) { warningEl.style.display = 'none'; warningEl.textContent = ''; }
    const fieldsWrap = document.getElementById('complianceWizardFields');
    if (fieldsWrap) fieldsWrap.innerHTML = `<div class="compliance-muted">Caricamento…</div>`;

    try {
      const templateKey = String(art.artifact_key || '').trim();
      const titleQ = encodeURIComponent(String(art.title || ''));
      const docTypeQ = encodeURIComponent(String(art.artifact_type || ''));
      const [schemaRes, profRes, inpRes] = await Promise.all([
        this.apiFetch(`compliance/templates.php?action=public_get_schema&template_key=${encodeURIComponent(templateKey)}&title=${titleQ}&doc_type=${docTypeQ}`, { method: 'GET', json: false }),
        this.apiFetch(`compliance/program_profile.php?action=get&program_id=${this.activeProgramId}`, { method: 'GET', json: false }),
        this.apiFetch(`compliance/artifact_inputs.php?action=get&artifact_id=${artifactId}`, { method: 'GET', json: false }),
      ]);

      const schemaRaw = schemaRes.data?.template?.input_schema_json || null;
      const hint = schemaRes.data?.template?.ai_hint || '';
      this.wiz.template = { input_schema_json: schemaRaw, ai_hint: hint };

      let schemaObj = null;
      if (schemaRaw && typeof schemaRaw === 'string') {
        try { schemaObj = JSON.parse(schemaRaw); } catch (e) { schemaObj = null; }
      }
      if (!schemaObj || typeof schemaObj !== 'object') {
        throw new Error('Schema compilazione non disponibile per questo template');
      }
      this.wiz.schema = schemaObj;
      this.wiz.profile = profRes.data?.profile || {};
      this.wiz.inputs = inpRes.data?.inputs || {};
      // Prefill inputs from profile (best-effort, only if empty)
      this.applyPrefillFromProfile();

      this.renderWizardFields();
      this.ensureWizardChatSplitUi();
      this.resetWizardChatState();
      this.openModal('complianceWizardModal');
      await this.loadWizardVersions();
    } catch (e) {
      if (fieldsWrap) fieldsWrap.innerHTML = '';
      if (warningEl) { warningEl.style.display = 'block'; warningEl.textContent = String(e?.message || e); }
      this.openModal('complianceWizardModal');
    }
  }

  resetWizardChatState() {
    this.wiz.chat = {
      messages: [],
      activeFieldKey: '',
      lastAnswerText: '',
      citations: [],
      knowledgeWarning: '',
      aiWarnings: [],
      collapsed: false,
      panel_open: true, // Chat panel open by default
    };
    this.renderWizardChat();
    // Ensure the panel is visible (add 'open' class)
    this.openWizardChatPanel();
  }

  getWizardFieldOptions() {
    const schema = this.wiz.schema;
    const out = [];
    const sections = Array.isArray(schema?.sections) ? schema.sections : [];
    sections.forEach(sec => {
      const fields = Array.isArray(sec?.fields) ? sec.fields : [];
      fields.forEach(f => {
        const key = String(f?.key || '').trim();
        if (!key) return;
        const label = String(f?.label || key).trim() || key;
        out.push({ key, label });
      });
    });
    return out;
  }

  ensureWizardChatSplitUi() {
    const modal = document.getElementById('complianceWizardModal');
    const body = modal?.querySelector?.('.compliance-modal-body');
    const fieldsWrap = document.getElementById('complianceWizardFields');
    if (!modal || !body || !fieldsWrap) return;

    // Inline button (always visible near top)
    const inlineBtn = document.getElementById('complianceWizardChatOpenBtnInline');
    if (inlineBtn && inlineBtn.dataset?.cnxBound !== '1') {
      inlineBtn.addEventListener('click', () => this.toggleWizardChatPanel());
      inlineBtn.dataset.cnxBound = '1';
    }

    // Cleanup legacy drawer/fab if present (avoid duplicates + blocking overlays)
    try { document.getElementById('complianceWizardChatFab')?.remove?.(); } catch (_) {}
    try { document.getElementById('complianceWizardChatDrawerBackdrop')?.remove?.(); } catch (_) {}
    try { document.getElementById('complianceWizardChatDrawer')?.remove?.(); } catch (_) {}

    // Ensure split wrapper exists and chat is inside modal (non-blocking)
    let split = document.getElementById('complianceWizardSplit');
    if (!split) {
      split = document.createElement('div');
      split.id = 'complianceWizardSplit';
      split.className = 'compliance-wizard-split';

      const main = document.createElement('div');
      main.className = 'compliance-wizard-split-main';

      const side = document.createElement('div');
      side.id = 'complianceWizardChatSide';
      side.className = 'compliance-wizard-split-chat';
      side.innerHTML = `<div class="compliance-wizard-chat" id="complianceWizardChatBox"></div>`;

      // Replace fieldsWrap in DOM with split container, then re-append fieldsWrap into main
      const parent = fieldsWrap.parentNode;
      if (parent) {
        parent.insertBefore(split, fieldsWrap);
        parent.removeChild(fieldsWrap);
      }
      main.appendChild(fieldsWrap);
      split.appendChild(main);
      split.appendChild(side);
    }

    this.ensureWizardChatBox();
  }

  openWizardChatPanel() {
    const side = document.getElementById('complianceWizardChatSide');
    if (side) side.classList.add('open');
    this.wiz.chat.panel_open = true;
    this.renderWizardChat();
  }

  closeWizardChatPanel() {
    const side = document.getElementById('complianceWizardChatSide');
    if (side) side.classList.remove('open');
    this.wiz.chat.panel_open = false;
  }

  toggleWizardChatPanel() {
    const open = !!this.wiz.chat.panel_open;
    if (open) this.closeWizardChatPanel();
    else this.openWizardChatPanel();
  }

  ensureWizardChatBox() {
    const box = document.getElementById('complianceWizardChatBox');
    if (!box) return;
    if (box.getAttribute('data-ready') === '1') {
      // refresh field options in case schema changed
      this.renderWizardChat();
      return;
    }
    box.setAttribute('data-ready', '1');
    box.innerHTML = `
      <div class="compliance-wizard-chat-head">
        <div class="compliance-wizard-chat-title">Chat AI</div>
        <div class="compliance-wizard-chat-controls">
          <select class="compliance-input" id="complianceWizardChatFieldSelect" style="min-width: 180px;"></select>
          <button type="button" class="btn btn-secondary btn-sm" id="complianceWizardChatCloseBtn">Chiudi</button>
          <button type="button" class="btn btn-secondary btn-sm" id="complianceWizardChatApplyBtn" disabled>Applica al campo</button>
          <button type="button" class="btn btn-secondary btn-sm" id="complianceWizardChatClearBtn">Pulisci</button>
        </div>
      </div>
      <div id="complianceWizardChatStatus" class="compliance-small" style="display:none; margin-bottom:8px;"></div>
      <div id="complianceWizardChatMessages" class="compliance-wizard-chat-messages"></div>
      <div class="compliance-wizard-chat-inputrow">
        <input id="complianceWizardChatInput" class="compliance-input" placeholder="Scrivi qui per migliorare i contenuti..." />
        <button type="button" class="btn btn-primary" id="complianceWizardChatSendBtn">Invia</button>
      </div>
      <div id="complianceWizardChatSources" class="compliance-wizard-chat-sources" style="display:none;"></div>
    `;

    document.getElementById('complianceWizardChatSendBtn')?.addEventListener('click', () => this.sendWizardChat());
    document.getElementById('complianceWizardChatInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        this.sendWizardChat();
      }
    });
    document.getElementById('complianceWizardChatApplyBtn')?.addEventListener('click', () => this.applyChatAnswerToField());
    document.getElementById('complianceWizardChatClearBtn')?.addEventListener('click', () => this.resetWizardChatState());
    document.getElementById('complianceWizardChatCloseBtn')?.addEventListener('click', () => this.closeWizardChatPanel());
    document.getElementById('complianceWizardChatFieldSelect')?.addEventListener('change', () => {
      const k = String(document.getElementById('complianceWizardChatFieldSelect')?.value || '').trim();
      this.wiz.chat.activeFieldKey = k;
      this.renderWizardChat();
    });

    this.renderWizardChat();
  }

  renderWizardChat() {
    const box = document.getElementById('complianceWizardChatBox');
    if (!box) return;
    const sel = document.getElementById('complianceWizardChatFieldSelect');
    const msgs = document.getElementById('complianceWizardChatMessages');
    const status = document.getElementById('complianceWizardChatStatus');
    const sources = document.getElementById('complianceWizardChatSources');
    const applyBtn = document.getElementById('complianceWizardChatApplyBtn');

    // Keep side panel state in sync
    const side = document.getElementById('complianceWizardChatSide');
    if (this.wiz.chat.panel_open) side?.classList?.add?.('open');
    else side?.classList?.remove?.('open');

    // Field options
    if (sel) {
      const opts = this.getWizardFieldOptions();
      const current = String(this.wiz.chat.activeFieldKey || sel.value || '').trim();
      sel.innerHTML = `<option value="">— Campo…</option>` + opts.map(o => {
        const k = this.escapeHtml(o.key);
        const label = this.escapeHtml(o.label);
        const selected = (current && current === o.key) ? ' selected' : '';
        return `<option value="${k}"${selected}>${label}</option>`;
      }).join('');
      if (current && opts.some(o => o.key === current)) sel.value = current;
      this.wiz.chat.activeFieldKey = String(sel.value || '').trim();
    }

    // Messages
    const arr = Array.isArray(this.wiz.chat.messages) ? this.wiz.chat.messages : [];
    if (msgs) {
      if (!arr.length) {
        msgs.innerHTML = `<div class="compliance-muted">Suggerimento: seleziona un campo e chiedi “Rendi più specifico e operativo” oppure “Espandi con esempi reali”.</div>`;
      } else {
        msgs.innerHTML = arr.map(m => {
          const role = m.role === 'user' ? 'Tu' : 'AI';
          const cls = m.role === 'user' ? 'compliance-wizard-chat-msg compliance-wizard-chat-msg-user' : 'compliance-wizard-chat-msg';
          return `<div class="${cls}">
            <div class="compliance-wizard-chat-msg-role">${this.escapeHtml(role)}</div>
            <div>${this.escapeHtml(String(m.content || ''))}</div>
          </div>`;
        }).join('');
        try { msgs.scrollTop = msgs.scrollHeight; } catch (_) {}
      }
    }

    // Status
    const warnParts = [];
    if (this.wiz.chat.knowledgeWarning) warnParts.push(String(this.wiz.chat.knowledgeWarning));
    if (Array.isArray(this.wiz.chat.aiWarnings) && this.wiz.chat.aiWarnings.length) {
      warnParts.push('AI: ' + this.wiz.chat.aiWarnings.join(', '));
    }
    if (status) {
      if (warnParts.length) {
        status.style.display = 'block';
        status.textContent = warnParts.join(' — ');
      } else {
        status.style.display = 'none';
        status.textContent = '';
      }
    }

    // Sources
    const cits = Array.isArray(this.wiz.chat.citations) ? this.wiz.chat.citations : [];
    if (sources) {
      if (!cits.length) {
        sources.style.display = 'none';
        sources.innerHTML = '';
      } else {
        sources.style.display = 'block';
        sources.innerHTML = `<div style="font-weight:700;">Fonti</div><ul>${
          cits.slice(0, 6).map(c => {
            const fid = parseInt(String(c.file_id || '0'), 10) || 0;
            const name = String(c.file_name || '');
            const path = String(c.logical_path || '');
            const link = fid > 0 ? `<a class="compliance-link" href="files.php?open_file_id=${fid}&open_mode=view" target="_blank" rel="noopener">${this.escapeHtml(name || ('#' + fid))}</a>` : this.escapeHtml(name || 'Documento');
            const meta = path ? ` <span class="compliance-small">${this.escapeHtml(path)}</span>` : '';
            return `<li>${link}${meta}</li>`;
          }).join('')
        }</ul>`;
      }
    }

    // Apply button
    if (applyBtn) {
      const can = !!(this.wiz.chat.activeFieldKey && String(this.wiz.chat.lastAnswerText || '').trim());
      applyBtn.disabled = !can;
    }
  }

  async sendWizardChat() {
    const art = this.wiz.artifact;
    if (!art?.id) return;
    const input = document.getElementById('complianceWizardChatInput');
    const status = document.getElementById('complianceWizardChatStatus');
    const btn = document.getElementById('complianceWizardChatSendBtn');

    const prompt = String(input?.value || '').trim();
    if (!prompt) return;
    if (input) input.value = '';

    if (!Array.isArray(this.wiz.chat.messages)) this.wiz.chat.messages = [];
    this.wiz.chat.messages.push({ role: 'user', content: prompt });
    this.renderWizardChat();

    try {
      if (this.wiz.isAiBusy) {
        if (status) { status.style.display = 'block'; status.textContent = 'AI già in corso…'; }
        return;
      }
      this.startAiProgress({ wrapEl: status, baseText: 'AI in corso…', expectedMs: 45000, btnEl: btn, btnTextBusy: 'AI…' });
      const res = await this.apiFetch('compliance/artifact_ai.php?action=chat', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          artifact_id: art.id,
          messages: this.wiz.chat.messages,
          selected_field_key: String(this.wiz.chat.activeFieldKey || '').trim() || null,
        }),
      });
      const answer = String(res.data?.answer_text || '').trim();
      if (!answer) throw new Error('Risposta AI vuota');
      this.wiz.chat.messages.push({ role: 'assistant', content: answer });
      this.wiz.chat.lastAnswerText = answer;
      this.wiz.chat.citations = Array.isArray(res.data?.citations) ? res.data.citations : [];
      this.wiz.chat.knowledgeWarning = String(res.data?.knowledge_warning || '').trim();
      this.wiz.chat.aiWarnings = Array.isArray(res.data?.ai_warnings) ? res.data.ai_warnings : [];
      this.stopAiProgress();
      this.renderWizardChat();
    } catch (e) {
      this.stopAiProgress();
      if (status) {
        status.style.display = 'block';
        status.textContent = String(e?.message || e);
      }
    }
  }

  applyChatAnswerToField() {
    const k = String(this.wiz.chat.activeFieldKey || '').trim();
    const txt = String(this.wiz.chat.lastAnswerText || '').trim();
    if (!k || !txt) return;
    this.wiz.inputs[k] = txt;
    this.renderWizardFields();
    this.renderWizardChat();
    const warn = document.getElementById('complianceWizardWarning');
    if (warn) {
      warn.style.display = 'block';
      warn.textContent = 'Testo chat applicato al campo (ricorda di salvare)';
      setTimeout(() => { if (warn) { warn.style.display = 'none'; warn.textContent = ''; } }, 1200);
    }
  }

  renderWizardFields() {
    const wrap = document.getElementById('complianceWizardFields');
    if (!wrap) return;
    const schema = this.wiz.schema;
    const inputs = this.wiz.inputs || {};
    const sections = Array.isArray(schema?.sections) ? schema.sections : [];

    // Always require "document objective" before AI/apply (additive)
    const objectiveKey = 'document_objective';
    const hasObjectiveField = sections.some(sec => (sec?.fields || []).some(f => String(f?.key || '').trim() === objectiveKey));
    if (!hasObjectiveField) {
      sections.unshift({
        title: 'Obiettivo documento',
        fields: [{
          key: objectiveKey,
          label: 'Obiettivo del documento (obbligatorio)',
          type: 'textarea',
          required: true,
          ai: false,
        }],
      });
    }

    const requiredKeys = [];
    const allKeys = [];
    sections.forEach(sec => {
      (sec?.fields || []).forEach(f => {
        if (!f || typeof f !== 'object') return;
        const k = String(f.key || '').trim();
        if (!k) return;
        allKeys.push(k);
        if (f.required) requiredKeys.push(k);
      });
    });
    const filledRequired = requiredKeys.filter(k => String(inputs[k] || '').trim() !== '').length;
    const completeness = requiredKeys.length ? Math.round((filledRequired / requiredKeys.length) * 100) : 100;

    wrap.innerHTML = `
      <div class="compliance-muted" style="margin-bottom:10px;">
        Completezza (campi obbligatori): <strong>${completeness}%</strong>
      </div>
      ${sections.map(sec => {
        const title = String(sec?.title || '').trim();
        const fields = Array.isArray(sec?.fields) ? sec.fields : [];
        return `
          <div class="compliance-field-row">
            <div class="compliance-field-row-head">
              <div class="compliance-field-title">${this.escapeHtml(title || 'Sezione')}</div>
              <div class="compliance-small">${fields.length} campi</div>
            </div>
            ${fields.map(f => this.renderWizardField(f)).join('')}
          </div>
        `;
      }).join('')}
    `;

    // bind inputs (supports checkbox/select/textarea)
    wrap.querySelectorAll('[data-field-key]').forEach(el => {
      const handler = () => {
        const k = String(el.getAttribute('data-field-key') || '').trim();
        if (!k) return;
        const tag = String(el.tagName || '').toLowerCase();
        const type = String(el.getAttribute('type') || '').toLowerCase();
        if (tag === 'input' && type === 'checkbox') {
          this.wiz.inputs[k] = el.checked ? '1' : '0';
        } else {
          this.wiz.inputs[k] = String(el.value || '');
        }
      };
      const focusHandler = () => {
        const k = String(el.getAttribute('data-field-key') || '').trim();
        if (!k) return;
        this.wiz.chat.activeFieldKey = k;
        this.renderWizardChat();
        // Non-blocking: auto-open chat when focusing a field (best-effort)
        if (!this.wiz.chat.panel_open) {
          this.openWizardChatPanel();
        }
      };
      // auto-grow textareas for readability
      if (String(el.tagName || '').toLowerCase() === 'textarea') {
        this.autoGrowTextarea(el);
      }
      el.addEventListener('input', handler);
      el.addEventListener('change', handler);
      el.addEventListener('focus', focusHandler);
      if (String(el.tagName || '').toLowerCase() === 'textarea') {
        el.addEventListener('input', () => this.autoGrowTextarea(el));
      }
    });

    // bind AI per-field
    wrap.querySelectorAll('[data-action="ai-field"]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const k = String(btn.getAttribute('data-field-key') || '').trim();
        if (!k) return;
        await this.suggestFieldWithAi(k, btn);
      });
    });
  }

  renderWizardField(f) {
    if (!f || typeof f !== 'object') return '';
    const key = String(f.key || '').trim();
    if (!key) return '';
    const label = String(f.label || key).trim();
    const type = String(f.type || 'text').trim().toLowerCase();
    const required = !!f.required;
    const ai = !!f.ai;
    const value = String((this.wiz.inputs || {})[key] || '');

    let inputHtml = '';
    if (type === 'textarea' || type === 'multiline') {
      inputHtml = `<textarea class="compliance-input" rows="6" data-field-key="${this.escapeHtml(key)}">${this.escapeHtml(value)}</textarea>`;
    } else if (type === 'list') {
      inputHtml = `<textarea class="compliance-input" rows="6" data-field-key="${this.escapeHtml(key)}" placeholder="1 per riga">${this.escapeHtml(value)}</textarea>`;
    } else if (type === 'select') {
      const opts = Array.isArray(f.options) ? f.options : [];
      inputHtml = `<select class="compliance-input" data-field-key="${this.escapeHtml(key)}">${
        opts.map(o => {
          const ov = String(o?.value ?? o ?? '').trim();
          const ol = String(o?.label ?? o ?? '').trim() || ov;
          const sel = (ov !== '' && ov === value) ? ' selected' : '';
          return `<option value="${this.escapeHtml(ov)}"${sel}>${this.escapeHtml(ol)}</option>`;
        }).join('')
      }</select>`;
    } else if (type === 'number') {
      inputHtml = `<input type="number" class="compliance-input" data-field-key="${this.escapeHtml(key)}" value="${this.escapeHtml(value)}" />`;
    } else if (type === 'date') {
      inputHtml = `<input type="date" class="compliance-input" data-field-key="${this.escapeHtml(key)}" value="${this.escapeHtml(value)}" />`;
    } else if (type === 'boolean') {
      const checked = (value === '1' || value.toLowerCase() === 'true' || value.toLowerCase() === 'si') ? ' checked' : '';
      inputHtml = `<label style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" data-field-key="${this.escapeHtml(key)}"${checked} />
        <span>${this.escapeHtml(label)}</span>
      </label>`;
    } else {
      inputHtml = `<input class="compliance-input" data-field-key="${this.escapeHtml(key)}" value="${this.escapeHtml(value)}" />`;
    }

    const aiBtn = ai
      ? `<button type="button" class="btn btn-secondary btn-sm" data-action="ai-field" data-field-key="${this.escapeHtml(key)}">Suggerisci</button>`
      : ``;

    return `
      <div style="margin-bottom:10px;">
        <div class="compliance-field-row-head" style="margin-bottom:6px;">
          <div>
            <div style="font-weight:700;">${this.escapeHtml(label)}${required ? ' <span class="compliance-small">(obbl.)</span>' : ''}</div>
            <div class="compliance-small"><code>{{${this.escapeHtml(key)}}}</code></div>
          </div>
          <div class="compliance-field-actions">${aiBtn}</div>
        </div>
        ${inputHtml}
      </div>
    `;
  }

  async saveWizardInputs() {
    const art = this.wiz.artifact;
    if (!art?.id) return;
    const warningEl = document.getElementById('complianceWizardWarning');
    const showWarn = (t) => {
      if (!warningEl) return;
      warningEl.style.display = 'block';
      warningEl.textContent = String(t || '');
    };
    if (warningEl) { warningEl.style.display = 'none'; warningEl.textContent = ''; }

    try {
      await this.apiFetch('compliance/artifact_inputs.php?action=save', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ artifact_id: art.id, inputs: this.wiz.inputs || {} })
      });
      showWarn('Dati salvati');
      setTimeout(() => { if (warningEl) { warningEl.style.display = 'none'; warningEl.textContent = ''; } }, 900);
      this.renderWizardFields();
    } catch (e) {
      showWarn(String(e?.message || e));
    }
  }

  async applyWizardToDocument() {
    const art = this.wiz.artifact;
    if (!art?.id) return;
    const obj = String((this.wiz.inputs || {})['document_objective'] || '').trim();
    if (!obj) {
      const warningEl = document.getElementById('complianceWizardWarning');
      if (warningEl) {
        warningEl.style.display = 'block';
        warningEl.textContent = 'Inserisci prima l’obiettivo del documento.';
      }
      return;
    }
    const warningEl = document.getElementById('complianceWizardWarning');
    const showWarn = (t) => {
      if (!warningEl) return;
      warningEl.style.display = 'block';
      warningEl.textContent = String(t || '');
    };
    if (warningEl) { warningEl.style.display = 'none'; warningEl.textContent = ''; }

    try {
      // save first (best-effort)
      await this.apiFetch('compliance/artifact_inputs.php?action=save', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ artifact_id: art.id, inputs: this.wiz.inputs || {} })
      });

      const res = await this.apiFetch('compliance/artifact_apply.php?action=apply_to_document', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ artifact_id: art.id })
      });

      const warnings = Array.isArray(res.data?.warnings) ? res.data.warnings : [];
      if (warnings.length) {
        showWarn('Completato con avvisi:\n' + warnings.join('\n'));
      } else {
        showWarn('Documento aggiornato');
        setTimeout(() => { if (warningEl) { warningEl.style.display = 'none'; warningEl.textContent = ''; } }, 1200);
      }

      // Open the UPDATED main file (not the __v snapshot) in a new tab
      const openUrl = String(res.data?.open_url || '').trim();
      if (openUrl) {
        // Ask before opening (better UX)
        try {
          const ok = window.confirm('Documento aggiornato. Vuoi aprirlo ora?');
          if (ok) window.open(openUrl, '_blank', 'noopener');
        } catch (_) {
          // fallback: do not auto-open
        }
      }

      await this.loadWizardVersions();
      await this.refresh();
    } catch (e) {
      showWarn(String(e?.message || e));
    }
  }

  async loadWizardVersions() {
    const box = document.getElementById('complianceWizardVersionsBox');
    const list = document.getElementById('complianceWizardVersions');
    const art = this.wiz.artifact;
    if (!box || !list) return;
    if (!art?.file_id) { box.style.display = 'none'; return; }

    const can = (this.userRole === 'manager' || this.userRole === 'super_admin');
    if (!can) { box.style.display = 'none'; return; }

    box.style.display = 'block';
    list.innerHTML = 'Caricamento…';

    try {
      const res = await this.apiFetch(`compliance/artifact_apply.php?action=versions_list&file_id=${art.file_id}`, { method: 'GET', json: false });
      const versions = Array.isArray(res.data?.versions) ? res.data.versions : [];
      if (!versions.length) {
        list.innerHTML = '<span class="compliance-muted">Nessuna versione</span>';
        return;
      }
      list.innerHTML = `
        <div style="overflow-x:auto;">
          <div class="compliance-muted" style="margin-bottom:8px;">
            Nota: i file con suffisso <code>__v</code> sono <strong>snapshot</strong> create prima dell’applicazione e possono risultare vuote.
            Per lavorare sul documento aggiornato usa “Apri in OnlyOffice” in alto nel modal.
          </div>
          <table class="compliance-table">
            <thead>
              <tr><th>Nome</th><th>Data</th><th>Azioni</th></tr>
            </thead>
            <tbody>
              ${versions.slice(0, 3).map(v => {
                const vid = v.version_file_id;
                const name = v.version_name || ('#' + vid);
                return `<tr>
                  <td>${this.escapeHtml(String(name))}</td>
                  <td class="compliance-muted">${this.escapeHtml(String(v.created_at || ''))}</td>
                  <td>
                    <a class="compliance-link" href="files.php?open_file_id=${vid}&open_mode=view" target="_blank" rel="noopener">Apri</a>
                    <button type="button" class="btn btn-secondary btn-sm" data-action="restore" data-version-file-id="${vid}" style="margin-left:8px;">Ripristina</button>
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      `;

      list.querySelectorAll('[data-action="restore"]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const vid = parseInt(String(btn.getAttribute('data-version-file-id') || '0'), 10) || 0;
          if (!vid) return;
          await this.restoreVersion(art.file_id, vid);
        });
      });
    } catch (e) {
      list.textContent = `Errore: ${String(e?.message || e)}`;
    }
  }

  async restoreVersion(fileId, versionFileId) {
    const warningEl = document.getElementById('complianceWizardWarning');
    const showWarn = (t) => {
      if (!warningEl) return;
      warningEl.style.display = 'block';
      warningEl.textContent = String(t || '');
    };
    try {
      const res = await this.apiFetch('compliance/artifact_apply.php?action=restore_version', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ file_id: fileId, version_file_id: versionFileId })
      });
      const warnings = Array.isArray(res.data?.warnings) ? res.data.warnings : [];
      showWarn(warnings.length ? ('Ripristinato con avvisi:\n' + warnings.join('\n')) : 'Versione ripristinata');
      await this.loadWizardVersions();
      await this.refresh();
    } catch (e) {
      showWarn(String(e?.message || e));
    }
  }

  async suggestFieldWithAi(fieldKey, btnEl = null) {
    const art = this.wiz.artifact;
    if (!art?.id) return;
    const wrap = document.getElementById('complianceWizardWarning');
    const showWarn = (t) => {
      if (!wrap) return;
      wrap.style.display = 'block';
      wrap.textContent = String(t || '');
    };
    try {
      if (this.wiz.isAiBusy) {
        showWarn('AI già in corso…');
        return;
      }
      this.startAiProgress({ wrapEl: wrap, baseText: 'AI in corso…', expectedMs: 22000, btnEl, btnTextBusy: 'AI…' });
      const res = await this.apiFetch('compliance/artifact_ai.php?action=suggest_field', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ artifact_id: art.id, field_key: fieldKey, current_values: { profile: this.wiz.profile, inputs: this.wiz.inputs } })
      });
      const txt = String(res.data?.suggestion_text || '').trim();
      if (!txt) throw new Error('Suggerimento vuoto');
      this.wiz.inputs[fieldKey] = txt;
      this.renderWizardFields();
      this.stopAiProgress();
      const warns = Array.isArray(res.data?.ai_warnings) ? res.data.ai_warnings : [];
      const note = warns.length ? ` (sanificato: ${warns.join(', ')})` : '';
      showWarn('Suggerimento applicato (ricorda di salvare)' + note);
      setTimeout(() => { if (wrap) { wrap.style.display = 'none'; wrap.textContent = ''; } }, 1200);
    } catch (e) {
      this.stopAiProgress();
      showWarn(String(e?.message || e));
    }
  }

  async generateWizardDraftWithAi() {
    const art = this.wiz.artifact;
    if (!art?.id) return;
    const obj = String((this.wiz.inputs || {})['document_objective'] || '').trim();
    if (!obj) {
      const warningEl = document.getElementById('complianceWizardWarning');
      if (warningEl) {
        warningEl.style.display = 'block';
        warningEl.textContent = 'Inserisci prima l’obiettivo del documento.';
      }
      return;
    }
    const wrap = document.getElementById('complianceWizardWarning');
    const showWarn = (t) => {
      if (!wrap) return;
      wrap.style.display = 'block';
      wrap.textContent = String(t || '');
    };
    try {
      if (this.wiz.isAiBusy) {
        showWarn('AI già in corso…');
        return;
      }
      const btn = document.getElementById('complianceWizardAiDraftBtn');
      this.startAiProgress({ wrapEl: wrap, baseText: 'AI in corso…', expectedMs: 45000, btnEl: btn, btnTextBusy: 'AI…' });
      const res = await this.apiFetch('compliance/artifact_ai.php?action=generate_document_draft', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ artifact_id: art.id })
      });
      const m = res.data?.suggested_values_map || {};
      if (!m || typeof m !== 'object') throw new Error('Risposta AI non valida');
      Object.keys(m).forEach(k => {
        const v = String(m[k] || '').trim();
        if (v) this.wiz.inputs[k] = v;
      });
      this.renderWizardFields();
      this.stopAiProgress();
      const warns = Array.isArray(res.data?.ai_warnings) ? res.data.ai_warnings : [];
      const note = warns.length ? ` (sanificato: ${warns.slice(0, 4).join(', ')}${warns.length > 4 ? ', …' : ''})` : '';
      showWarn('Bozza AI pronta (ricorda di salvare)' + note);
      setTimeout(() => { if (wrap) { wrap.style.display = 'none'; wrap.textContent = ''; } }, 1400);
    } catch (e) {
      this.stopAiProgress();
      showWarn(String(e?.message || e));
    }
  }

  debounce(fn, ms) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  valueToTextarea(value) {
    if (Array.isArray(value)) {
      return value.map(x => String(x ?? '').trim()).filter(Boolean).join('\n');
    }
    if (value === null || value === undefined) return '';
    if (typeof value === 'object') {
      try { return JSON.stringify(value); } catch (_) { return ''; }
    }
    return String(value);
  }

  autoGrowTextarea(el) {
    try {
      if (!el || String(el.tagName || '').toLowerCase() !== 'textarea') return;
      el.style.height = 'auto';
      const max = 520;
      const next = Math.min(max, Math.max(120, el.scrollHeight || 0));
      el.style.height = `${next}px`;
    } catch (_) {}
  }

  applyPrefillFromProfile() {
    const schema = this.wiz.schema;
    const inputs = this.wiz.inputs || {};
    const profile = this.wiz.profile || {};
    const sections = Array.isArray(schema?.sections) ? schema.sections : [];
    sections.forEach(sec => {
      const fields = Array.isArray(sec?.fields) ? sec.fields : [];
      fields.forEach(f => {
        const key = String(f?.key || '').trim();
        if (!key) return;
        const existing = String(inputs[key] ?? '').trim();
        if (existing !== '') return; // do not overwrite user inputs
        if (!Object.prototype.hasOwnProperty.call(profile, key)) return;
        const pv = this.valueToTextarea(profile[key]);
        if (String(pv || '').trim() !== '') {
          inputs[key] = pv;
        }
      });
    });
    this.wiz.inputs = inputs;
  }

  escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
}

window.addEventListener('DOMContentLoaded', () => {
  try {
    const app = new ComplianceDashboard();
    app.init();
    window.complianceDashboard = app;
  } catch (e) {
    console.error('[ComplianceDashboard] init failed', e);
  }
});

