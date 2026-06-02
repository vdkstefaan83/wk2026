/* global Alpine, window, document */
/**
 * Prediction wizard logic.
 *  - Live group standings (FIFA-style sort) per match input.
 *  - Reactive R32 bracket once group stage is filled.
 *  - Walks R16 → Final by propagating user picks.
 *  - Debounced autosave to /api/predictions/:id/autosave.
 */
function predictionWizard(cfg) {
  return {
    step: 'groups',
    formId: cfg.formId,
    csrf: cfg.csrf,
    readonly: cfg.readonly,
    label: '',

    // Local mutable mirror of standings (groupCode -> rows[])
    standings: {},
    // Per-group cache of match results keyed by matchId
    groupMatches: {},     // groupCode -> [{match_id, home_id, away_id, home, away}]
    // Best thirds (all 12 ranked) — populated once groups are complete
    thirdsRanked: [],
    // R32 bracket (16 slots) computed locally
    bracket: { r32: [] },
    downstream: {
      r16: [
        { slot: 'R16-01', feeds: ['R32-01','R32-03'] },
        { slot: 'R16-02', feeds: ['R32-02','R32-05'] },
        { slot: 'R16-03', feeds: ['R32-04','R32-06'] },
        { slot: 'R16-04', feeds: ['R32-07','R32-08'] },
        { slot: 'R16-05', feeds: ['R32-11','R32-12'] },
        { slot: 'R16-06', feeds: ['R32-09','R32-10'] },
        { slot: 'R16-07', feeds: ['R32-14','R32-16'] },
        { slot: 'R16-08', feeds: ['R32-13','R32-15'] },
      ],
      qf: [
        { slot: 'QF-01', feeds: ['R16-01','R16-02'] },
        { slot: 'QF-02', feeds: ['R16-05','R16-06'] },
        { slot: 'QF-03', feeds: ['R16-03','R16-04'] },
        { slot: 'QF-04', feeds: ['R16-07','R16-08'] },
      ],
      sf: [
        { slot: 'SF-01', feeds: ['QF-01','QF-02'] },
        { slot: 'SF-02', feeds: ['QF-03','QF-04'] },
      ],
      final: { slot: 'F-01', feeds: ['SF-01','SF-02'] },
    },
    picks: {},
    winnerTeamId: '',
    topscorerPlayerId: '',
    topscorerSearch: '', // legacy, kept for older bindings
    topscorerQuery: '',
    tiebreakerValue: '',
    lastSaved: null,

    init() {
      this.standings = JSON.parse(JSON.stringify(cfg.initial.standings || {}));
      this.bracket   = cfg.initial.bracket || { r32: [] };
      this.downstream= cfg.initial.downstream || this.downstream;
      this.picks     = cfg.initial.picks || {};
      this.label     = (document.querySelector('h1 input')?.value) || '';
      // Winner is derived from the F-01 slot pick (which is authoritative),
      // falling back to the persisted forms.winner_team_id from the server.
      this.winnerTeamId      = (this.picks['F-01'] || cfg.initial.winnerTeamId || '');
      this.topscorerPlayerId = cfg.initial.topscorerPlayerId || '';
      this.topscorerQuery    = cfg.initial.topscorerCustomName || this.lookupPlayerName(this.topscorerPlayerId) || '';
      this.tiebreakerValue   = (cfg.initial.tiebreakerValue ?? '') === null ? '' : (cfg.initial.tiebreakerValue ?? '');

      // Seed groupMatches from server-rendered initial data
      const initial = window.__initialGroupMatches || {};
      Object.keys(initial).forEach(code => {
        this.groupMatches[code] = {};
        initial[code].forEach(m => {
          this.groupMatches[code][m.match_id] = m;
        });
      });

      this.refreshBracket();
      this.autosaveTimer = null;
      this.dirty = false;

      // Flush a pending autosave when the user closes the tab or navigates away.
      window.addEventListener('beforeunload', () => this.flushBeforeUnload());
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') this.flushBeforeUnload();
      });
    },

    // ---------- helpers ----------
    onScoreChange(matchId, homeId, awayId, groupCode, ev) {
      if (this.readonly) return;
      const wrapper = ev.target.parentElement;
      const homeInput = wrapper.querySelector('input[name$="[home]"]');
      const awayInput = wrapper.querySelector('input[name$="[away]"]');
      // Strip non-digits as the user types so "-5" or "2.5" can't stay in the box.
      const cleanRaw = s => (s || '').replace(/[^0-9]/g, '');
      if (homeInput.value !== cleanRaw(homeInput.value)) homeInput.value = cleanRaw(homeInput.value);
      if (awayInput.value !== cleanRaw(awayInput.value)) awayInput.value = cleanRaw(awayInput.value);
      const home = homeInput.value === '' ? null : Math.max(0, parseInt(homeInput.value, 10));
      const away = awayInput.value === '' ? null : Math.max(0, parseInt(awayInput.value, 10));

      if (!this.groupMatches[groupCode]) this.groupMatches[groupCode] = {};
      this.groupMatches[groupCode][matchId] = {
        match_id: matchId, home_team_id: homeId, away_team_id: awayId,
        home, away,
      };
      this.recomputeGroup(groupCode);
      this.refreshBracket();
      this.scheduleAutosave({ scoreMatchId: matchId, home, away });
    },

    recomputeGroup(groupCode) {
      const rows = this.standings[groupCode] || [];
      if (rows.length === 0) return;
      const teams = rows.map(r => ({ id: r.team_id, name: r.name, flag_emoji: r.flag_emoji }));
      const stats = {};
      teams.forEach(t => stats[t.id] = {
        team_id: t.id, name: t.name, flag_emoji: t.flag_emoji,
        played: 0, won: 0, drawn: 0, lost: 0, gf: 0, ga: 0, gd: 0, points: 0,
      });
      const matches = Object.values(this.groupMatches[groupCode] || {});
      const played = [];
      for (const m of matches) {
        if (m.home === null || m.home === undefined || m.away === null || m.away === undefined) continue;
        if (!stats[m.home_team_id] || !stats[m.away_team_id]) continue;
        const s1 = stats[m.home_team_id], s2 = stats[m.away_team_id];
        s1.played++; s2.played++;
        s1.gf += m.home; s1.ga += m.away;
        s2.gf += m.away; s2.ga += m.home;
        if (m.home > m.away) { s1.won++; s2.lost++; s1.points += 3; }
        else if (m.home < m.away) { s2.won++; s1.lost++; s2.points += 3; }
        else { s1.drawn++; s2.drawn++; s1.points++; s2.points++; }
        played.push(m);
      }
      Object.values(stats).forEach(s => s.gd = s.gf - s.ga);
      const overall = Object.values(stats).sort((a,b) => this.cmp(a,b,played, Object.values(stats)));
      // Resolve 3+ way ties via head-to-head
      const resolved = this.resolveTies(overall, played);
      resolved.forEach((r,i) => r.position = i+1);
      this.standings[groupCode] = resolved;
    },

    cmp(a, b, played) {
      if (a.points !== b.points) return b.points - a.points;
      if (a.gd     !== b.gd)     return b.gd - a.gd;
      if (a.gf     !== b.gf)     return b.gf - a.gf;
      const h2h = this.headToHead([a.team_id, b.team_id], played);
      const ha = h2h[a.team_id], hb = h2h[b.team_id];
      if (ha.points !== hb.points) return hb.points - ha.points;
      if (ha.gd     !== hb.gd)     return hb.gd - ha.gd;
      if (ha.gf     !== hb.gf)     return hb.gf - ha.gf;
      return a.name.localeCompare(b.name);
    },

    resolveTies(rows, played) {
      const n = rows.length;
      let i = 0;
      while (i < n) {
        let j = i + 1;
        while (j < n
            && rows[j].points === rows[i].points
            && rows[j].gd     === rows[i].gd
            && rows[j].gf     === rows[i].gf) j++;
        if (j - i >= 3) {
          const tied = rows.slice(i, j);
          const ids  = tied.map(r => r.team_id);
          const h2h  = this.headToHead(ids, played);
          tied.sort((a, b) => {
            const ha = h2h[a.team_id], hb = h2h[b.team_id];
            if (ha.points !== hb.points) return hb.points - ha.points;
            if (ha.gd     !== hb.gd)     return hb.gd - ha.gd;
            if (ha.gf     !== hb.gf)     return hb.gf - ha.gf;
            return a.name.localeCompare(b.name);
          });
          rows.splice(i, tied.length, ...tied);
        }
        i = j;
      }
      return rows;
    },

    headToHead(ids, played) {
      const set = new Set(ids);
      const s = {}; ids.forEach(id => s[id] = { points: 0, gf: 0, ga: 0, gd: 0 });
      for (const m of played) {
        if (!set.has(m.home_team_id) || !set.has(m.away_team_id)) continue;
        s[m.home_team_id].gf += m.home; s[m.home_team_id].ga += m.away;
        s[m.away_team_id].gf += m.away; s[m.away_team_id].ga += m.home;
        if (m.home > m.away)      s[m.home_team_id].points += 3;
        else if (m.away > m.home) s[m.away_team_id].points += 3;
        else { s[m.home_team_id].points++; s[m.away_team_id].points++; }
      }
      ids.forEach(id => s[id].gd = s[id].gf - s[id].ga);
      return s;
    },

    // ---------- bracket ----------
    isGroupsComplete() {
      // Check all 12 groups have all 6 matches with both scores filled in
      const required = 12 * 6;
      let filled = 0;
      Object.values(this.groupMatches).forEach(g => {
        Object.values(g).forEach(m => {
          if (m.home !== null && m.away !== null && m.home !== undefined && m.away !== undefined) filled++;
        });
      });
      // Fall back: use server-side standings for an initial completeness signal
      if (filled === 0) {
        const sum = Object.values(this.standings).reduce((acc, rows) => {
          return acc + (rows.length ? rows.reduce((a, r) => a + r.played, 0) / 2 : 0);
        }, 0);
        return sum >= required;
      }
      return filled >= required;
    },

    // FIFA 2026 R32 structure (matches matchnumbers 73–88).
    // Defs: 'W1X' = winner of group X, '2X' = runner-up of group X,
    //       '3#A,B,…' = third-placed team from one of the listed groups.
    R32_DEF: {
      'R32-01': ['2A',  '2B'],
      'R32-02': ['W1C', '2F'],
      'R32-03': ['W1E', '3#A,B,C,D,F'],
      'R32-04': ['W1F', '2C'],
      'R32-05': ['2E',  '2I'],
      'R32-06': ['W1I', '3#C,D,F,G,H'],
      'R32-07': ['W1A', '3#C,E,F,H,I'],
      'R32-08': ['W1L', '3#E,H,I,J,K'],
      'R32-09': ['W1G', '3#A,E,H,I,J'],
      'R32-10': ['W1D', '3#B,E,F,I,J'],
      'R32-11': ['W1H', '2J'],
      'R32-12': ['2K',  '2L'],
      'R32-13': ['W1B', '3#E,F,G,I,J'],
      'R32-14': ['2D',  '2G'],
      'R32-15': ['W1J', '2H'],
      'R32-16': ['W1K', '3#D,E,I,J,L'],
    },

    refreshBracket() {
      // Always recompute ranked thirds (even with partial group data) so the
      // UI can show progress as the user types.
      const allThirds = [];
      Object.keys(this.standings).forEach(code => {
        const rows = this.standings[code];
        if (rows[2]) allThirds.push({ ...rows[2], group: code });
      });
      allThirds.sort((a, b) => this.qualityCmp(a, b));
      this.thirdsRanked = allThirds;

      if (!this.isGroupsComplete()) {
        this.bracket = { r32: [] };
        return;
      }
      const firsts  = {};
      const seconds = {};
      Object.keys(this.standings).forEach(code => {
        const rows = this.standings[code];
        if (rows[0]) firsts[code]  = { ...rows[0], group: code };
        if (rows[1]) seconds[code] = { ...rows[1], group: code };
      });

      const qualified = allThirds.slice(0, 8);
      const thirdsByGroup = {};
      qualified.forEach(t => thirdsByGroup[t.group] = t);

      // Backtracking: assign each R32 third-slot a third whose group is allowed.
      const thirdSlots = {};
      Object.entries(this.R32_DEF).forEach(([slot, [l, r]]) => {
        [l, r].forEach(side => {
          if (side.startsWith('3#')) thirdSlots[slot] = side.slice(2).split(',').map(s => s.trim());
        });
      });
      const slotOrder = Object.keys(thirdSlots);
      const assigned = {};
      const used = new Set();
      const solve = (idx) => {
        if (idx === slotOrder.length) return true;
        const slot = slotOrder[idx];
        for (const g of thirdSlots[slot]) {
          if (!thirdsByGroup[g] || used.has(g)) continue;
          assigned[slot] = thirdsByGroup[g];
          used.add(g);
          if (solve(idx + 1)) return true;
          used.delete(g);
          delete assigned[slot];
        }
        return false;
      };
      solve(0);

      const resolve = (def, slot) => {
        if (def.startsWith('W1')) return firsts[def[2]] || null;
        if (/^2[A-L]$/.test(def)) return seconds[def[1]] || null;
        if (def.startsWith('3#')) return assigned[slot] || null;
        return null;
      };

      this.bracket = {
        r32: Object.entries(this.R32_DEF).map(([slot, [l, r]]) => ({
          slot,
          home: resolve(l, slot),
          away: resolve(r, slot),
        })),
      };
    },

    qualityCmp(a, b) {
      if (a.points !== b.points) return b.points - a.points;
      if (a.gd     !== b.gd)     return b.gd - a.gd;
      if (a.gf     !== b.gf)     return b.gf - a.gf;
      return (a.group || '').localeCompare(b.group || '');
    },

    matchesForStage(stage) {
      if (stage === 'r32') return this.bracket.r32 || [];
      const meta = stage === 'r16' ? this.downstream.r16 :
                   stage === 'qf'  ? this.downstream.qf  :
                   stage === 'sf'  ? this.downstream.sf  :
                   stage === 'final' ? [this.downstream.final] : [];
      const result = [];
      for (const m of meta) {
        const home = this.teamFromSlot(m.feeds[0]);
        const away = this.teamFromSlot(m.feeds[1]);
        result.push({ slot: m.slot, home, away });
      }
      return result;
    },

    slotsForStage(stage) {
      if (stage === 'r32') return (this.bracket.r32 || []).map(m => m.slot);
      const meta = stage === 'r16' ? this.downstream.r16 :
                   stage === 'qf'  ? this.downstream.qf  :
                   stage === 'sf'  ? this.downstream.sf  :
                   stage === 'final' ? [this.downstream.final] : [];
      return meta.map(m => m.slot);
    },

    teamFromSlot(feedSlot) {
      const teamId = this.picks[feedSlot];
      if (!teamId) return null;
      // Find team object from R32 home/away or higher stage by walking back
      const r32 = (this.bracket.r32 || []).find(m => m.slot === feedSlot);
      if (r32) {
        if (r32.home && r32.home.team_id === teamId) return r32.home;
        if (r32.away && r32.away.team_id === teamId) return r32.away;
      }
      // Recurse via downstream meta
      const find = (meta) => {
        for (const m of meta) {
          if (m.slot !== feedSlot) continue;
          const h = this.teamFromSlot(m.feeds[0]);
          const a = this.teamFromSlot(m.feeds[1]);
          if (h && h.team_id === teamId) return h;
          if (a && a.team_id === teamId) return a;
          return null;
        }
        return undefined;
      };
      let r = find(this.downstream.r16);
      if (r !== undefined) return r;
      r = find(this.downstream.qf);
      if (r !== undefined) return r;
      r = find(this.downstream.sf);
      if (r !== undefined) return r;
      if (feedSlot === 'F-01') {
        const h = this.teamFromSlot('SF-01'); if (h && h.team_id === teamId) return h;
        const a = this.teamFromSlot('SF-02'); if (a && a.team_id === teamId) return a;
      }
      // Fallback: look up in teams cache
      const cache = window.__teamsById || {};
      return cache[teamId] || null;
    },

    pickSlot(slot, teamId) {
      if (this.readonly) return;
      if (!teamId) return;
      this.picks = { ...this.picks, [slot]: teamId };

      // Invalidate downstream picks that no longer make sense
      this.invalidateDownstream(slot);

      // Auto-set winner when final picked
      if (slot === 'F-01') {
        this.winnerTeamId = teamId;
      }
      this.scheduleAutosave({ slot, teamId });
    },

    invalidateDownstream(changedSlot) {
      const order = ['R32-','R16-','QF-','SF-','F-'];
      const stage = changedSlot.split('-')[0] + '-';
      const idx = order.indexOf(stage);
      if (idx === -1) return;
      for (let i = idx+1; i < order.length; i++) {
        Object.keys(this.picks).forEach(k => {
          if (k.startsWith(order[i])) {
            const team = this.picks[k];
            // Only clear if the team no longer appears in this slot's possible feeders
            const m = this.matchesForStage(this.stageFromPrefix(order[i])).find(x => x.slot === k);
            if (!m || (m.home?.team_id !== team && m.away?.team_id !== team)) {
              delete this.picks[k];
            }
          }
        });
      }
      this.picks = { ...this.picks };
    },

    stageFromPrefix(p) {
      return { 'R32-':'r32', 'R16-':'r16','QF-':'qf','SF-':'sf','F-':'final' }[p];
    },

    countPicks(prefix) {
      return Object.keys(this.picks).filter(k => k.startsWith(prefix) && this.picks[k]).length;
    },

    STEPS: [
      { key: 'groups',    label: 'Group stage' },
      { key: 'r32',       label: 'Round of 32' },
      { key: 'r16',       label: 'Round of 16' },
      { key: 'qf',        label: 'Quarter-finals' },
      { key: 'sf',        label: 'Semi-finals' },
      { key: 'final',     label: 'Final' },
      { key: 'topscorer', label: 'Top scorer' },
      { key: 'summary',   label: 'Summary' },
    ],

    get currentStepIndex() {
      return this.STEPS.findIndex(s => s.key === this.step);
    },
    get prevStep() {
      const i = this.currentStepIndex;
      return i > 0 ? this.STEPS[i - 1] : null;
    },
    get nextStep() {
      const i = this.currentStepIndex;
      return i >= 0 && i < this.STEPS.length - 1 ? this.STEPS[i + 1] : null;
    },
    goToStep(key) {
      this.step = key;
      // Scroll to the top of the wizard so the new section is in view
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    isStageComplete(key) {
      if (this.readonly) return true;
      switch (key) {
        case 'groups': {
          const gc = this.groupCompletion();
          return gc.filled >= gc.total;
        }
        case 'r32':       return this.countPicks('R32') >= 16;
        case 'r16':       return this.countPicks('R16') >= 8;
        case 'qf':        return this.countPicks('QF')  >= 4;
        case 'sf':        return this.countPicks('SF')  >= 2;
        case 'final':     return this.countPicks('F')   >= 1;
        case 'topscorer': return !!this.topscorerPlayerId;
        case 'summary':
          return this.tiebreakerValue !== '' && this.tiebreakerValue !== null && !isNaN(Number(this.tiebreakerValue));
        default: return true;
      }
    },

    get missingChecks() {
      const out = [];
      const gc = this.groupCompletion();
      if (gc.filled < gc.total) out.push((gc.total - gc.filled) + ' group score(s)');
      const ko = [['R32',16,'1/16'],['R16',8,'1/8'],['QF',4,'1/4'],['SF',2,'1/2'],['F',1,'final']];
      for (const [p, need, label] of ko) {
        const have = this.countPicks(p);
        if (have < need) out.push((need - have) + ' ' + label + ' pick(s)');
      }
      if (!this.topscorerPlayerId) out.push('top scorer');
      if (this.tiebreakerValue === '' || this.tiebreakerValue === null || isNaN(Number(this.tiebreakerValue))) {
        out.push('tiebreaker');
      }
      return out;
    },

    get canSubmit() {
      return !this.readonly && this.missingChecks.length === 0;
    },

    get submitButtonLabel() {
      if (this.canSubmit) return '🚀 Submit final';
      const n = this.missingChecks.length;
      return n === 0 ? '🚀 Submit final' : '⏳ Submit when complete (' + n + ' to go)';
    },

    submitFinal() {
      if (!this.canSubmit) {
        this.step = 'summary';
        return;
      }
      if (!confirm('Submit definitively? This cannot be undone.')) return;
      const form = document.getElementById('prediction-form');
      form.action = form.action.replace(/\/save$/, '/submit');
      form.submit();
    },

    groupCompletion() {
      let total = 0, filled = 0;
      Object.values(this.standings).forEach(rows => {
        total += 6;
        if (!rows.length) return;
        filled += Math.floor(rows.reduce((a,r) => a + r.played, 0) / 2);
      });
      // overlay locally edited group matches
      Object.values(this.groupMatches).forEach(g => {
        // local edits override but we already include played; nothing extra needed
      });
      return { filled, total };
    },

    completionForGroup(code) {
      const rows = this.standings[code] || [];
      const played = rows.length ? Math.floor(rows.reduce((a,r) => a + r.played, 0) / 2) : 0;
      return played + '/6';
    },

    get filteredPlayers() {
      const q = (this.topscorerQuery || this.topscorerSearch || '').toLowerCase().trim();
      const all = window.__players || [];
      if (!q) return [];
      return all.filter(p =>
        (p.name || '').toLowerCase().includes(q)
        || (p.team_name || '').toLowerCase().includes(q)
      ).slice(0, 8);
    },

    get hasExactPlayerMatch() {
      const q = (this.topscorerQuery || '').trim().toLowerCase();
      if (!q) return false;
      return (window.__players || []).some(p => (p.name || '').toLowerCase() === q);
    },

    get topscorerCustomName() {
      // Custom-name pathway disabled: topscorer must be picked from list.
      return '';
    },

    pickTopscorer(p) {
      if (this.readonly) return;
      this.topscorerPlayerId = p.id;
      this.topscorerQuery = p.name;
      this.scheduleAutosave();
    },

    clearTopscorer() {
      if (this.readonly) return;
      this.topscorerPlayerId = '';
      this.topscorerQuery = '';
      this.scheduleAutosave();
    },

    onTopscorerInput() {
      // If user edits the text away from the selected player's name, unlink the player.
      if (this.topscorerPlayerId) {
        const p = (window.__players || []).find(x => x.id == this.topscorerPlayerId);
        if (!p || (p.name || '').toLowerCase() !== (this.topscorerQuery || '').toLowerCase()) {
          this.topscorerPlayerId = '';
        }
      }
      this.scheduleAutosave();
    },

    lookupPlayerName(id) {
      if (!id) return '';
      const p = (window.__players || []).find(x => x.id == id);
      return p ? p.name : '';
    },

    get winnerLabel() {
      if (!this.winnerTeamId) return '';
      const t = (window.__teamsById || {})[this.winnerTeamId];
      return t ? `${t.flag_emoji || ''} ${t.name}` : '';
    },

    get topscorerLabel() {
      if (!this.topscorerPlayerId) return '';
      const p = (window.__players || []).find(x => x.id == this.topscorerPlayerId);
      return p ? `${p.name}${p.team_name ? ' (' + p.team_name + ')' : ''}` : '';
    },

    get lastSavedLabel() {
      if (!this.lastSaved) return 'not yet';
      const d = new Date(this.lastSaved);
      return d.toLocaleTimeString();
    },

    // ---------- autosave ----------
    scheduleAutosave() {
      if (this.readonly) return;
      this.dirty = true;
      clearTimeout(this.autosaveTimer);
      this.autosaveTimer = setTimeout(() => this.autosave(), 800);
    },

    /** Best-effort sync save when the user is about to leave the page.
     *  Uses fetch with keepalive so the request survives navigation. */
    flushBeforeUnload() {
      if (this.readonly || !this.dirty) return;
      clearTimeout(this.autosaveTimer);
      const winner = this.picks['F-01'] || this.winnerTeamId || null;
      const payload = {
        _csrf: this.csrf,
        scores: this.flattenScores(),
        slots:  Object.keys(this.picks).map(s => ({ slot: s, team_id: this.picks[s] })),
        winner_team_id:        winner,
        topscorer_player_id:   this.topscorerPlayerId || null,
        topscorer_custom_name: this.topscorerCustomName || null,
        tiebreaker_value:      this.tiebreakerValue === '' ? null : Number(this.tiebreakerValue),
        label: this.label || undefined,
      };
      try {
        fetch('/api/predictions/' + this.formId + '/autosave', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
          body: JSON.stringify(payload),
          keepalive: true,
        });
      } catch (e) { /* nothing we can do — tab is closing */ }
    },

    async autosave() {
      if (this.readonly) return;
      // Always derive winner from the F-01 slot pick so we never accidentally
      // overwrite the persisted winner with a stale empty value.
      const winner = this.picks['F-01'] || this.winnerTeamId || null;
      this.winnerTeamId = winner || '';
      const payload = {
        _csrf: this.csrf,
        scores: this.flattenScores(),
        slots:  Object.keys(this.picks).map(s => ({ slot: s, team_id: this.picks[s] })),
        winner_team_id: winner,
        topscorer_player_id: this.topscorerPlayerId || null,
        topscorer_custom_name: this.topscorerCustomName || null,
        tiebreaker_value: this.tiebreakerValue === '' ? null : Number(this.tiebreakerValue),
        label: this.label || undefined,
      };
      try {
        const r = await fetch(`/api/predictions/${this.formId}/autosave`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
          body: JSON.stringify(payload),
        });
        if (r.ok) { this.lastSaved = Date.now(); this.dirty = false; }
      } catch (e) {
        console.warn('autosave failed', e);
      }
    },

    save() {
      document.getElementById('prediction-form').submit();
    },

    flattenScores() {
      const out = [];
      Object.values(this.groupMatches).forEach(g => {
        Object.values(g).forEach(m => {
          out.push({ match_id: m.match_id, home: m.home, away: m.away });
        });
      });
      return out;
    },
  };
}

window.predictionWizard = predictionWizard;
