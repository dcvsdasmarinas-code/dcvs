// Client & Pet Database (embedded in dashboard-staff.php#client-pets)
// Expects globals: CLIENTS, PETS, VIEW_OWNER (output by the page before this script)
var currentProfile = null;

var state = { mode:'clients', view:'table', group:'all', quick:'', brgy:'', type:'', date:'', petSearch:'', search:'', sort:'newest', page:1, perPage:10, selected:0, sortCol:'', sortDir:'' };

function cpdEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function vaxInfo(v){ var s=String(v||'').toLowerCase(); var ok = s.indexOf('vaccinated')!==-1 && s.indexOf('not')===-1; return ok ? {l:'Vaccinated',c:'vax-yes'} : {l:'Not vaccinated',c:'vax-no'}; }
function avatarHtml(item, size){ return item.avatar ? '<img src="'+cpdEsc(item.avatar)+'" alt="">' : cpdEsc(item.initials); }
function petPhotoHtml(p){
  if (p.photo) return '<img src="'+cpdEsc(p.photo)+'" alt="">';
  return cpdEsc(p.initials);
}

// Column definitions (per mode) — mirrors shadcn columns config
var COLUMN_DEFS = {
  clients: [
    { id:'name', label:'Client Name', sort:'name' },
    { id:'ref', label:'Resident ID', sort:'ref' },
    { id:'email', label:'Email', sort:'email' },
    { id:'phone', label:'Mobile Number', sort:'contact' },
    { id:'brgy', label:'Barangay', sort:'brgy' },
    { id:'pets', label:'Pets', sort:'pets' },
    { id:'date', label:'Registered on', sort:'date' },
    { id:'status', label:'Status', sort:'status' }
  ],
  pets: [
    { id:'name', label:'Pet Name', sort:'name' },
    { id:'ref', label:'Pet ID', sort:'ref' },
    { id:'owner', label:'Owner Name', sort:'owner' },
    { id:'ownerRef', label:'Owner ID', sort:'ownerRef' },
    { id:'species', label:'Species', sort:'species' },
    { id:'gender', label:'Sex', sort:'gender' },
    { id:'age', label:'Age', sort:'age' },
    { id:'vax', label:'Vaccination', sort:'vax' },
    { id:'brgy', label:'Barangay', sort:'brgy' },
    { id:'date', label:'Registered on', sort:'date' }
  ]
};
function getDefs(){ return COLUMN_DEFS[state.mode]; }
function colSortVal(col, x){
  if (state.mode==='clients'){
    if (col==='name') return String(x.name||'').toLowerCase();
    if (col==='ref') return String(x.ref||'').toLowerCase();
    if (col==='email') return String(x.email||'').toLowerCase();
    if (col==='contact') return String(x.phone||'').toLowerCase();
    if (col==='brgy') return String(x.brgy||'').toLowerCase();
    if (col==='pets') return x.pets||0;
    if (col==='date') return x.ts||0;
    if (col==='status') return String(x.status||'').toLowerCase();
  } else {
    if (col==='name') return String(x.name||'').toLowerCase();
    if (col==='ref') return String(x.ref||'').toLowerCase();
    if (col==='owner') return String(x.ownerName||'').toLowerCase();
    if (col==='ownerRef') return String(x.ownerRef||'').toLowerCase();
    if (col==='species') return String(x.type||'').toLowerCase();
    if (col==='gender') return String(x.gender||'').toLowerCase();
    if (col==='age') return String(x.age||'');
    if (col==='vax') return x.vaxOk ? 1 : 0;
    if (col==='brgy') return String(x.brgy||'').toLowerCase();
    if (col==='date') return x.ts||0;
  }
  return 0;
}
function toggleSort(col){
  if (state.sortCol===col){ state.sortDir = state.sortDir==='asc' ? 'desc' : 'asc'; }
  else { state.sortCol = col; state.sortDir = 'asc'; }
  state.page = 1;
  render();
}

function currentList(){
  var list = state.mode==='clients' ? CLIENTS : PETS;
  var now = Date.now()/1000;
  list = list.filter(function(x){
    if (state.mode==='clients'){
      if (state.group==='with' && x.pets===0) return false;
      if (state.group==='none' && x.pets>0) return false;
    } else {
      if (state.group==='cat' && x.type!=='cat') return false;
      if (state.group==='dog' && x.type!=='dog') return false;
    }
    if (state.quick==='recent' && (now - x.ts) > 30*86400) return false;
    if (state.date && x.dateKey !== state.date) return false;
    if (state.mode==='clients'){
      if (state.quick==='active' && x.status!=='Active') return false;
      if (state.quick==='inactive' && x.status!=='Inactive') return false;
    } else {
      if (state.quick==='vax' && x.vaxOk!==true) return false;
      if (state.quick==='novax' && x.vaxOk!==false) return false;
    }
    if (state.brgy && x.brgy!==state.brgy) return false;
    if (state.type){
      if (state.mode==='pets'){
        if (x.type!==state.type) return false;
      } else {
        var ownsType = PETS.some(function(p){ return p.ownerId===x.id && p.type===state.type; });
        if (!ownsType) return false;
      }
    }
    var ps = state.petSearch.toLowerCase();
    if (ps && state.mode==='clients'){
      var owned = PETS.filter(function(p){ return p.ownerId===x.id; });
      var hit = owned.some(function(p){ return p.name.toLowerCase().indexOf(ps)!==-1 || p.ref.toLowerCase().indexOf(ps)!==-1; });
      if (!hit) return false;
    }
    if (ps && state.mode==='pets'){
      if (x.name.toLowerCase().indexOf(ps)===-1 && x.ref.toLowerCase().indexOf(ps)===-1) return false;
    }
    var q = state.search.toLowerCase();
    if (q){
      if (state.mode==='clients'){
        if (x.name.toLowerCase().indexOf(q)===-1 && x.email.toLowerCase().indexOf(q)===-1 && x.phone.toLowerCase().indexOf(q)===-1 && x.ref.toLowerCase().indexOf(q)===-1 && x.brgy.toLowerCase().indexOf(q)===-1) return false;
      } else {
        if (x.name.toLowerCase().indexOf(q)===-1 && x.ownerName.toLowerCase().indexOf(q)===-1 && x.ref.toLowerCase().indexOf(q)===-1 && x.brgy.toLowerCase().indexOf(q)===-1 && (x.ownerRef||'').toLowerCase().indexOf(q)===-1) return false;
      }
    }
    return true;
  });
  if (state.sortCol){
    var dir = state.sortDir==='desc' ? -1 : 1;
    var col = state.sortCol;
    list.sort(function(a,b){
      var va = colSortVal(col, a), vb = colSortVal(col, b);
      if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
      if (va < vb) return -1 * dir;
      if (va > vb) return 1 * dir;
      return 0;
    });
  } else if (state.sort==='newest') list.sort(function(a,b){ return b.ts-a.ts; });
  else if (state.sort==='oldest') list.sort(function(a,b){ return a.ts-b.ts; });
  else if (state.sort==='name') list.sort(function(a,b){ return a.name.localeCompare(b.name); });
  else if (state.sort==='pets') list.sort(function(a,b){ return b.pets-a.pets; });
  else if (state.sort==='species') list.sort(function(a,b){ return a.type.localeCompare(b.type); });
  return list;
}

function clientCellHtml(col, c){
  if (col==='name') return '<div class="client-cell"><div class="avatar">'+avatarHtml(c)+'</div><div class="client-name">'+cpdEsc(c.name)+'</div></div>';
  if (col==='ref') return '<span class="client-id">'+cpdEsc(c.ref)+'</span>';
  if (col==='email') return cpdEsc(c.email||'-');
  if (col==='phone') return cpdEsc(c.phone||'-');
  if (col==='brgy') return '<div class="client-name">'+cpdEsc(c.brgy||'-')+'</div>';
  if (col==='pets') return '<span class="pets-badge">'+c.pets+'</span>';
  if (col==='date') return cpdEsc(c.date);
  if (col==='status') return '<span class="status-pill '+(c.status==='Active'?'status-active':'status-inactive')+'">'+c.status+'</span>';
  return '';
}
function petCellHtml(col, p){
  var v = vaxInfo(p.vax);
  if (col==='name') return '<div class="client-cell"><div class="avatar">'+petPhotoHtml(p)+'</div><div class="client-name">'+cpdEsc(p.name)+'</div></div>';
  if (col==='ref') return '<span class="client-id">'+cpdEsc(p.ref)+'</span>';
  if (col==='owner') return '<div class="client-name">'+cpdEsc(p.ownerName)+'</div>';
  if (col==='ownerRef') return '<span class="client-id">'+cpdEsc(p.ownerRef||'')+'</span>';
  if (col==='species') return cpdEsc(p.typeLabel);
  if (col==='gender') return cpdEsc(p.gender);
  if (col==='age') return cpdEsc(p.age);
  if (col==='vax') return '<span class="vax-pill '+v.c+'">'+v.l+'</span>';
  if (col==='brgy') return '<div class="client-name">'+cpdEsc(p.brgy||'-')+'</div>';
  if (col==='date') return cpdEsc(p.date);
  return '';
}
function rowHtml(mode, item){
  var selCls = state.selected===item.id ? 'selected' : '';
  var clickAttr = mode==='clients' ? " onclick=\"selectClient("+item.id+")\"" : " onclick=\"selectPet("+item.ownerId+")\"";
  var html = '<tr data-id="'+item.id+'" class="sd-tr '+selCls+'"'+clickAttr+'>';
  if (mode==='clients'){
    html += '<td class="sd-td"><input type="checkbox" class="sd-table-cb row-cb" value="'+item.id+'" onclick="event.stopPropagation();cpdUpdateBulkBar()" /></td>';
  }
  var defs = getDefs();
  for (var i=0;i<defs.length;i++){
    var col = defs[i];
    html += '<td class="sd-td">'+(mode==='clients'?clientCellHtml(col.id,item):petCellHtml(col.id,item))+'</td>';
  }
  var mId = mode==='clients' ? item.id : item.ownerId;
  html += '<td class="sd-td sd-td-action"><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();openRowMenu(event,'+mId+')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
  html += '</tr>';
  return html;
}
function clientRowHtml(c){ return rowHtml('clients', c); }
function petRowHtml(p){ return rowHtml('pets', p); }

function clientCardHtml(c){
  return '<div class="client-card '+(state.selected===c.id?'selected':'')+'" onclick="selectClient('+c.id+')">'+
    '<div class="client-card-top"><div class="avatar">'+avatarHtml(c)+'</div><div style="min-width:0;"><div class="client-card-name">'+cpdEsc(c.name)+'</div><div class="client-card-id">'+cpdEsc(c.ref)+'</div></div></div>'+
    '<div class="client-card-meta"><span class="pets-badge">'+c.pets+' Pet'+(c.pets!==1?'s':'')+'</span><span class="status-pill '+(c.status==='Active'?'status-active':'status-inactive')+'">'+c.status+'</span></div>'+
    '<div style="margin-top:8px; font-size:12px; color:var(--ink-500);">'+cpdEsc(c.brgy||'-')+'</div>'+
    '<div style="margin-top:3px; font-size:12px; color:var(--ink-400);">'+cpdEsc(c.phone||'-')+'</div>'+
  '</div>';
}

function petCardHtml(p){
  var v = vaxInfo(p.vax);
  return '<div class="client-card" onclick="selectPet('+p.ownerId+')">'+
    '<div class="client-card-top"><div class="avatar">'+petPhotoHtml(p)+'</div><div style="min-width:0;"><div class="client-card-name">'+cpdEsc(p.name)+'</div><div class="client-card-id">'+cpdEsc(p.ref)+'</div></div></div>'+
    '<div style="font-size:12px; color:var(--ink-500); margin-bottom:10px;">'+cpdEsc(p.typeLabel)+' &bull; '+cpdEsc(p.gender)+' &bull; '+cpdEsc(p.age)+'</div>'+
    '<div class="client-card-meta"><span class="vax-pill '+v.c+'">'+v.l+'</span></div>'+
    '<div style="margin-top:8px; font-size:12px; color:var(--ink-500);">'+cpdEsc(p.ownerName)+'</div>'+
  '</div>';
}

function sortHeaderHtml(col){
  var active = state.sortCol===col.sort;
  var icon;
  if (active && state.sortDir==='asc') icon = '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>';
  else if (active && state.sortDir==='desc') icon = '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
  else icon = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
  return '<button class="th-sort'+(active?' th-sorted':'')+'" onclick="toggleSort(\''+col.sort+'\')">'+col.label+'<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+icon+'</svg></button>';
}
function renderTableHead(){
  var head = document.getElementById('tableHead');
  var html = '<tr class="sd-tr">';
  var defs = getDefs();
  if (state.mode==='clients'){
    html += '<th class="sd-th" style="width:40px;"><input type="checkbox" class="sd-table-cb" id="cpdSelectAll" onclick="cpdToggleSelectAll(this)" /></th>';
  }
  for (var i=0;i<defs.length;i++){
    var col = defs[i];
    html += '<th class="sd-th">'+sortHeaderHtml(col)+'</th>';
  }
  html += '<th class="sd-th sd-th-action"></th>';
  head.innerHTML = html;
}
function render(){
  var list = currentList();
  var total = list.length;
  var per = state.perPage;
  var pages = Math.max(1, Math.ceil(total/per));
  if (state.page > pages) state.page = pages;
  if (state.page < 1) state.page = 1;
  var start = (state.page-1)*per;
  var slice = list.slice(start, start+per);

  renderTableHead();
  var tbody = document.getElementById('dataRows');
  var cards = document.getElementById('cardsWrap');

  var visibleCount = getDefs().length + (state.mode==='clients' ? 2 : 1);
  if (total === 0){
    tbody.innerHTML = '<tr class="sd-tr"><td class="sd-td" colspan="'+visibleCount+'"><div class="no-results"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><p>No '+state.mode+' found.</p></div></td></tr>';
    cards.innerHTML = '';
  } else if (state.mode==='clients'){
    tbody.innerHTML = slice.map(clientRowHtml).join('');
    cards.innerHTML = slice.map(clientCardHtml).join('');
  } else {
    tbody.innerHTML = slice.map(petRowHtml).join('');
    cards.innerHTML = slice.map(petCardHtml).join('');
  }

  document.getElementById('tableWrap').style.display = state.view==='table' ? '' : 'none';
  cards.style.display = state.view==='cards' ? '' : 'none';

  renderPagination(pages);
  cpdUpdateBulkBar();
}

function renderPagination(pages){
  var prev = document.getElementById('pagePrev');
  var next = document.getElementById('pageNext');
  var first = document.getElementById('pageFirst');
  var last = document.getElementById('pageLast');
  var label = document.getElementById('pageLabel');
  if (prev) prev.disabled = state.page<=1;
  if (next) next.disabled = state.page>=pages;
  if (first) first.disabled = state.page<=1;
  if (last) last.disabled = state.page>=pages;
  if (label) label.textContent = 'Page ' + state.page + ' of ' + pages;
  if (first) first.onclick = function(){ state.page = 1; render(); };
  if (prev) prev.onclick = function(){ state.page = Math.max(1, state.page-1); render(); };
  if (next) next.onclick = function(){ state.page = Math.min(pages, state.page+1); render(); };
  if (last) last.onclick = function(){ state.page = pages; render(); };
}

function goPage(p){
  if (p==='prev') p = state.page-1;
  if (p==='next') p = state.page+1;
  if (p < 1) return;
  state.page = p;
  render();
}

function setPerPage(v){ state.perPage = parseInt(v,10); state.page = 1; render(); }
function setSort(v){ state.sort = v; state.page = 1; render(); }
function setSearch(v){ state.search = v; state.page = 1; render(); }
function setPetSearch(v){ state.petSearch = v; state.page = 1; render(); }
function setBrgy(v){ state.brgy = v; state.page = 1; render(); }
function setType(v){ state.type = v; state.page = 1; render(); }
function cpdSetDate(){
  state.date = document.getElementById('cpdDateFrom').value;
  state.page = 1;
  render();
}

function setGroup(v){
  state.group = v; state.page = 1;
  document.querySelectorAll('.cpd .filter-item[data-filter="group"]').forEach(function(b){
    b.classList.toggle('active', b.getAttribute('data-value')===v);
  });
  var showTools = v === 'all';
  ['cpdExportBtn','cpdAddBtn'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.style.display = showTools ? '' : 'none';
  });
  render();
}
function setQuick(v){
  state.quick = v; state.page = 1;
  document.querySelectorAll('.cpd .filter-item[data-filter="quick"]').forEach(function(b){
    b.classList.toggle('active', b.getAttribute('data-value')===v);
  });
  render();
}

function clearFilters(){
  state.group = 'all';
  state.quick = '';
  state.brgy = '';
  state.type = '';
  state.date = '';
  state.petSearch = '';
  state.search = '';
  state.page = 1;
  var ms = document.getElementById('mainSearch');
  var ps = document.getElementById('petSearch');
  var bf = document.getElementById('brgyFilter');
  var tf = document.getElementById('typeFilter');
  if (ms) ms.value = '';
  if (ps) ps.value = '';
  if (bf) bf.value = '';
  if (tf) tf.value = '';
  var dateFrom = document.getElementById('cpdDateFrom');
  var dateTo = document.getElementById('cpdDateTo');
  if (dateFrom) dateFrom.value = '';
  if (dateTo) dateTo.value = '';
  var dateButton = document.querySelector('[data-date-filter="cpd"] .staff-date-filter-button');
  if (dateButton) dateButton.classList.remove('has-date');
  document.querySelectorAll('.cpd .filter-item').forEach(function(b){
    b.classList.toggle('active', b.getAttribute('data-filter')==='group' && b.getAttribute('data-value')==='all');
  });
  render();
}

function setMode(mode){
  state.mode = mode; state.page = 1;
  document.querySelectorAll('#modeSeg .seg-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-mode')===mode); });
  document.querySelectorAll('.filter-group[data-group]').forEach(function(g){ g.style.display = g.getAttribute('data-group')===mode ? '' : 'none'; });
  document.querySelectorAll('.cpd .filter-item').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.cpd .filter-item[data-filter="group"][data-value="all"]').forEach(function(b){ b.classList.add('active'); });
  state.group='all'; state.quick='';
  var qf = document.getElementById('quickFilter');
  if (qf) qf.value = '';
  var sortSel = document.getElementById('sortSelect');
  if (sortSel){
    var speciesOpt = sortSel.querySelector('option[value="species"]');
    var petsOpt = sortSel.querySelector('option[value="pets"]');
    if (speciesOpt) speciesOpt.style.display = mode==='pets' ? '' : 'none';
    if (petsOpt) petsOpt.style.display = mode==='clients' ? '' : 'none';
    if (mode==='pets' && state.sort==='pets') state.sort='newest';
    sortSel.value = state.sort;
  }
  render();
}

function setView(view){
  state.view = view;
  document.querySelectorAll('#viewSeg .seg-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-view')===view); });
  render();
}

// ── Row menu ──
function openRowMenu(ev, userId){
  ev.stopPropagation();
  var pop = document.getElementById('rowMenuPop');
  var c = CLIENTS.find(function(x){ return x.id===userId; }) || { id:userId, name:'Client' };
  pop.innerHTML =
    '<button class="row-menu-item" onclick="loadClient('+c.id+',\'profile\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View Profile</button>'+
    '<button class="row-menu-item" onclick="loadClient('+c.id+',\'pets\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="4" cy="8" r="2"/><circle cx="7" cy="16" r="2"/><path d="M6.5 18c-1 -3 1.5 -5 4 -5c1.5 0 2 1 3.5 1c2.5 0 5 -2 4 -5"/></svg>View Pets</button>'+
    '<button class="row-menu-item" onclick="openEditClient('+c.id+')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Client</button>'+
    '<button class="row-menu-item danger" onclick="confirmArchive('+c.id+',\''+cpdEsc(c.name).replace(/'/g,"\\'")+'\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive Client</button>';
  var r = ev.currentTarget.getBoundingClientRect();
  pop.style.top = (r.bottom + 6) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 210) + 'px';
  pop.classList.add('open');
  document.addEventListener('click', closeRowMenuOnce);
}
function closeRowMenuOnce(e){
  if (e.target.closest && e.target.closest('.row-menu-pop')) return;
  document.getElementById('rowMenuPop').classList.remove('open');
  document.removeEventListener('click', closeRowMenuOnce);
}

// ── Client selection & profile ──
function selectClient(id){
  state.selected = id;
  document.querySelectorAll('#dataRows tr').forEach(function(r){ r.classList.toggle('selected', r.getAttribute('data-id')==String(id)); });
  loadClient(id, 'profile');
}
function selectPet(ownerId){
  loadClient(ownerId, 'pets');
}

function loadClient(userId, tab){
  state.selected = userId;
  document.querySelectorAll('#dataRows tr').forEach(function(r){ r.classList.toggle('selected', r.getAttribute('data-id')==String(userId)); });
  var box = document.getElementById('profileContent');
  box.innerHTML = '<div class="profile-loading"><i class="fas fa-spinner fa-spin"></i> Loading profile...</div>';
  openModal('clientProfileModal');
  fetch('client-database-handler.php?action=get_client&user_id=' + encodeURIComponent(userId))
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (!data.success){ cpdToast(data.message || 'Error loading profile', true); box.innerHTML = ''; return; }
      currentProfile = data;
      renderProfile(tab);
    })
    .catch(function(){ box.innerHTML = '<div class="profile-empty"><p>Network error loading profile.</p></div>'; });
}

function renderProfile(tab){
  var d = currentProfile;
  if (!d) return;
  var c = d.client;
  var pets = d.pets || [];
  var box = document.getElementById('profileContent');
  var activeTab = tab || 'profile';

  var headerEl = document.getElementById('profileHeader');
  headerEl.style.display = (activeTab === 'profile') ? '' : 'none';
  headerEl.innerHTML = activeTab === 'profile' ?
    '<div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;">'+
      '<div class="profile-avatar" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">'+ (c.avatar ? '<img src="'+cpdEsc(c.avatar)+'" alt="" style="width:100%;height:100%;object-fit:cover;">' : cpdEsc(c.initials)) +'</div>'+
      '<div style="display:flex;flex-direction:column;gap:2px;min-width:0;">'+
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Registered client</span>'+
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+cpdEsc(c.full_name)+'</span>'+
      '</div>'+
      '<span style="margin-left:auto;font-size:13px;font-weight:500;padding:4px 12px;border-radius:6px;font-family:Inter,sans-serif;white-space:nowrap;background:'+(c.status==='active'?'#ECFDF3':'#F1F5F9')+';color:'+(c.status==='active'?'#027A48':'#64748b')+';border:1px solid '+(c.status==='active'?'#D1FADF':'#E2E8F0')+';">'+(c.status==='active'?'Active':'Inactive')+'</span>'+
    '</div>' : '';

  var footer = document.getElementById('clientProfileFooter');
  if (footer) {
    footer.innerHTML =
      '<a class="sd-btn sd-btn-default" href="#" onclick="openClientComposeFromProfile(); return false;">Email/Message</a>' +
      '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="confirmArchive('+c.user_id+',\''+cpdEsc(c.full_name).replace(/'/g,"\\'")+'\')">Archive</button>';
  }

  box.innerHTML = '<div id="profileTabBody"></div>';

  document.getElementById('profileTabs').innerHTML =
    '<button class="rpt-tab'+(activeTab==='profile'?' active':'')+'" onclick="renderProfile(\'profile\')">Profile</button>'+
    '<button class="rpt-tab'+(activeTab==='pets'?' active':'')+'" onclick="renderProfile(\'pets\')">Pets ('+pets.length+')</button>'+
    '<button class="rpt-tab'+(activeTab==='history'?' active':'')+'" onclick="renderProfile(\'history\')">History</button>';

  var body = document.getElementById('profileTabBody');

  if (activeTab==='profile'){
    body.innerHTML =
      '<div style="margin-top:2px;"><div class="info-card-head"><div class="rpt-section-title-action"><h3 style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:0;">Client Information</h3><button type="button" class="cpd-edit-icon" onclick="openEditClient('+c.user_id+')" aria-label="Edit client" title="Edit client"><img src="assets/images/edit%20icon.png" alt=""></button></div>'+
      '</div>'+
      '<div class="client-profile-values-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;">'+
        '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Resident ID</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(c.reference_id||'-')+'</p></div></div>'+
        '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Barangay</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(c.barangay_name||'-')+'</p></div></div>'+
        '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Full Name</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(c.full_name)+'</p></div></div>'+
        '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Address</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(c.address||'-')+'</p></div></div>'+
        '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Email Address</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(c.email||'-')+'</p></div></div>'+
        '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Registration Date</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(c.registration_date)+'</p></div></div>'+
        '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">Mobile Number</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(c.phone||'-')+'</p></div></div>'+
      '</div></div>';
  } else if (activeTab==='pets'){
    body.innerHTML = pets.length ? pets.map(petCardProfile).join('') : '<p style="color:var(--ink-400); font-size:13px;">No registered pets.</p>';
  } else if (activeTab==='history'){
    var history = d.history || [];
    if (!history.length){
      body.innerHTML = '<div class="rpt-section-title">Client updates</div><p class="rpt-updates-empty">No client updates have been recorded yet.</p>';
    } else {
      body.innerHTML = '<div class="rpt-section-title">Client updates</div>'+
        '<div class="rpt-updates-timeline">'+history.map(function(h){
          return '<article class="rpt-updates-entry"><div class="rpt-updates-entry-head">'+
            '<span class="rpt-updates-entry-title">'+cpdEsc(h.t)+'</span>'+
            (h.d ? '<time class="rpt-updates-entry-date">'+cpdEsc(h.d)+'</time>' : '')+
            '</div><span class="rpt-updates-entry-actor">'+cpdEsc(h.a || 'DCVS')+'</span>'+
            (h.copy ? '<p class="rpt-updates-entry-copy">'+cpdEsc(h.copy)+'</p>' : '')+
            '</article>';
        }).join('')+'</div>';
    }
  }
}

function infoRow(icon, label, value){
  return '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;">'+label+'</p><p class="info-row-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;">'+cpdEsc(value)+'</p></div></div>';
}

function petCardProfile(p){
  var v = vaxInfo(p.vaccination_status);
  return '<div class="pet-card" onclick="openPetProfile('+p.pet_id+')">'+
    '<div class="pet-photo">'+(p.photo_url?'<img src="'+cpdEsc(p.photo_url)+'" alt="">':cpdEsc((p.pet_name||'?').substring(0,2).toUpperCase()))+'</div>'+
    '<div class="pet-info">'+
      '<div class="pet-name-row"><span class="pet-name">'+cpdEsc(p.pet_name)+'</span><span class="pet-id">'+cpdEsc(p.reference_id||'')+'</span></div>'+
      '<p class="pet-meta">'+cpdEsc(cap(p.pet_type))+' &bull; '+cpdEsc(cap(p.gender))+' &bull; '+cpdEsc(p.age ? (p.age+' '+(p.age==1?'year':'years')) : 'Unknown')+'</p>'+
      '<div class="pet-foot"><span class="vax-pill '+v.c+'">'+v.l+'</span><button class="view-pet-btn" onclick="event.stopPropagation();openPetProfile('+p.pet_id+')">View Pet <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></button></div>'+
    '</div></div>';
}

function cap(s){ return s ? String(s).charAt(0).toUpperCase()+String(s).slice(1) : s; }

function openPetProfile(petId){
  window.open('pet-profile.php?id='+petId+'&ref=staff', '_blank');
}

function closeProfile(){
  currentProfile = null;
  document.getElementById('profileContent').innerHTML =
    '<div class="profile-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><p>Select a client to view their profile</p></div>';
  closeModal('clientProfileModal');
}

// ── Add / Edit / Archive ──
function openAddClient(){ openModal('addClientModal'); }

var addAvatarInput = document.getElementById('addAvatar');
var addAvatarPreview = document.getElementById('addAvatarPreview');
var removeAddAvatar = document.getElementById('removeAddAvatar');
if (addAvatarInput) addAvatarInput.addEventListener('change', function(){
  var file = this.files && this.files[0];
  if (!file) return;
  var reader = new FileReader();
  reader.onload = function(e){
    addAvatarPreview.innerHTML = '<img src="' + e.target.result + '" alt="Client display picture preview">';
    removeAddAvatar.style.display = 'block';
  };
  reader.readAsDataURL(file);
});
if (removeAddAvatar) removeAddAvatar.addEventListener('click', function(){
  addAvatarInput.value = '';
  addAvatarPreview.innerHTML = '<i class="fas fa-user"></i>';
  removeAddAvatar.style.display = 'none';
});

function openEditClient(userId){
  var c = CLIENTS.find(function(x){ return x.id===userId; });
  if (c){
    document.getElementById('editUserId').value = c.id;
    document.getElementById('editFullName').value = c.name;
    document.getElementById('editEmail').value = c.email;
    document.getElementById('editPhone').value = c.phone;
    document.getElementById('editBarangay').value = c.brgyId || '';
    document.getElementById('editGender').value = c.gender || '';
    document.getElementById('editBirthdate').value = c.birthdate || '';
    document.getElementById('editStatus').value = c.status==='Active'?'active':'inactive';
    document.getElementById('editAddress').value = c.address;
  } else if (currentProfile && currentProfile.client && currentProfile.client.user_id===userId){
    var cc = currentProfile.client;
    document.getElementById('editUserId').value = cc.user_id;
    document.getElementById('editFullName').value = cc.full_name;
    document.getElementById('editEmail').value = cc.email||'';
    document.getElementById('editPhone').value = cc.phone||'';
    document.getElementById('editBarangay').value = cc.barangay_id||'';
    document.getElementById('editGender').value = cc.gender||'';
    document.getElementById('editBirthdate').value = cc.birthdate||'';
    document.getElementById('editStatus').value = cc.status==='active'?'active':'inactive';
    document.getElementById('editAddress').value = cc.address||'';
  }
  openModal('editClientModal');
}

var archiveId = 0;
function confirmArchive(id, name){
  archiveId = id;
  document.getElementById('archiveClientName').textContent = name;
  openModal('archiveClientModal');
}
function executeArchive(){
  var btn = document.getElementById('archiveConfirmBtn');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiving...';
  btn.disabled = true;
  var fd = new FormData();
  fd.append('action','archive_client');
  fd.append('user_id', archiveId);
  fetch('client-database-handler.php', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data.success){ closeModal('archiveClientModal'); cpdToast('Client archived'); setTimeout(function(){ location.reload(); }, 600); }
      else { cpdToast(data.message || 'Archive failed', true); btn.innerHTML = 'Archive Client'; btn.disabled=false; }
    })
    .catch(function(){ cpdToast('Network error', true); btn.innerHTML = 'Archive Client'; btn.disabled=false; });
}

// Forms
document.getElementById('addClientForm').addEventListener('submit', function(e){
  e.preventDefault();
  var fd = new FormData(this);
  fd.append('action','add_client');
  var btn = this.querySelector('button[type="submit"]');
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
  btn.disabled = true;
  fetch('client-database-handler.php', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      btn.innerHTML = 'Add Client';
      btn.disabled = false;
      if (data.success){
        closeModal('addClientModal');
        cpdToast(data.message || 'Client added');
        setTimeout(function(){ location.reload(); }, 700);
      } else {
        cpdToast(data.message || 'Failed to add client', true);
      }
    })
    .catch(function(){ btn.innerHTML = 'Add Client'; btn.disabled=false; cpdToast('Network error', true); });
});

document.getElementById('editClientForm').addEventListener('submit', function(e){
  e.preventDefault();
  var fd = new FormData(this);
  fd.append('action','update_client');
  var btn = this.querySelector('button[type="submit"]');
  btn.textContent = 'Saving...';
  btn.disabled = true;
  fetch('client-database-handler.php', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
      btn.textContent = 'Save changes';
      btn.disabled = false;
      if (data.success){
        if (window.cpdEditAdvance) { window.cpdEditAdvance(); return; }
        closeModal('editClientModal');
        cpdToast(data.message || 'Client updated');
        setTimeout(function(){ location.reload(); }, 700);
      } else {
        cpdToast(data.message || 'Failed to update client', true);
      }
    })
    .catch(function(){ btn.textContent = 'Save changes'; btn.disabled=false; cpdToast('Network error', true); });
});

// Toast
var cpdToastTimer = null;
function cpdToast(msg, isError){
  if (typeof window.portalToast === 'function') {
    if (isError) window.portalToast('Action failed', msg);
    else window.portalToast(msg);
    return;
  }
  var t = document.getElementById('cpdToast');
  t.textContent = msg;
  t.className = 'cpd-toast '+(isError?'error':'success')+' show';
  clearTimeout(cpdToastTimer);
  cpdToastTimer = setTimeout(function(){ t.classList.remove('show'); }, 3200);
}

// Export CSV of current filtered list
function exportCSV(){
  var list = currentList();
  var rows = [];
  if (state.mode==='clients'){
    rows.push(['Client Name','Resident ID','Email','Mobile Number','Barangay','Pets','Status','Registered On']);
    list.forEach(function(x){ rows.push([x.name,x.ref,x.email,x.phone,x.brgy,String(x.pets),x.status,x.date]); });
  } else {
    rows.push(['Pet Name','Pet ID','Owner Name','Owner ID','Species','Sex','Age','Vaccination','Barangay','Registered On']);
    list.forEach(function(x){ var v=vaxInfo(x.vax).l; rows.push([x.name,x.ref,x.ownerName,x.ownerRef||'',x.typeLabel,x.gender,x.age,v,x.brgy,x.date]); });
  }
  var csv = rows.map(function(r){ return r.map(function(c){ return '"'+String(c==null?'':c).replace(/"/g,'""')+'"'; }).join(','); }).join('\n');
  var a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent('\uFEFF'+csv);
  a.download = 'client-database.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

// Init
(function(){
  if (!document.getElementById('dataRows')) return;
  setMode('clients');
  var sortSel = document.getElementById('sortSelect');
  if (sortSel){
    var speciesOpt = sortSel.querySelector('option[value="species"]');
    if (speciesOpt) speciesOpt.style.display = 'none';
  }
  if (VIEW_OWNER){
    loadClient(VIEW_OWNER, 'profile');
  } else {
    document.addEventListener('DOMContentLoaded', function() {
      var section = document.getElementById('client-pets');
      if (section && getComputedStyle(section).display !== 'none' && CLIENTS.length) {
        render();
      }
    });
  }
})();

/* ── CPD bulk BEGIN: checkbox quick actions (same pattern as All Reports) ── */
var cpdSeq = null;
var cpdEditOrig = null;

function cpdSelectedIds() {
  var ids = [];
  document.querySelectorAll('#dataRows .row-cb:checked').forEach(function (cb) { ids.push(String(cb.value)); });
  return ids;
}

function cpdSelectedClients() {
  var ids = cpdSelectedIds();
  return CLIENTS.filter(function (c) { return ids.indexOf(String(c.id)) !== -1; });
}

function cpdToggleSelectAll(cb) {
  document.querySelectorAll('#dataRows .row-cb').forEach(function (b) { b.checked = cb.checked; });
  cpdUpdateBulkBar();
}
window.cpdToggleSelectAll = cpdToggleSelectAll;

function cpdUpdateBulkBar() {
  var checked = document.querySelectorAll('#dataRows .row-cb:checked');
  var cnt = document.getElementById('cpdBulkCount');
  if (cnt) cnt.textContent = checked.length;
  var bar = document.getElementById('cpdBulkBar');
  if (bar) bar.classList.toggle('active', checked.length > 0);
  var all = document.getElementById('cpdSelectAll');
  var total = document.querySelectorAll('#dataRows .row-cb').length;
  if (all) {
    all.checked = total > 0 && checked.length === total;
    all.indeterminate = checked.length > 0 && checked.length < total;
  }
}
window.cpdUpdateBulkBar = cpdUpdateBulkBar;

function cpdFlash(msg, title) {
  try { sessionStorage.setItem('cpdBulkToast', JSON.stringify({ title: title || 'Action complete', message: msg })); } catch (e) {}
  setTimeout(function () { location.reload(); }, 700);
}

// Post-reload flash toast (set by bulk action summaries before location.reload())
try {
  var _cpdFlash = sessionStorage.getItem('cpdBulkToast');
  if (_cpdFlash) {
    sessionStorage.removeItem('cpdBulkToast');
    var _cpdFlashData;
    try { _cpdFlashData = JSON.parse(_cpdFlash); } catch (e) { _cpdFlashData = null; }
    var _showCpdFlash = function () {
      if (_cpdFlashData && typeof window.portalToast === 'function') window.portalToast(_cpdFlashData.title, _cpdFlashData.message);
      else cpdToast(_cpdFlashData ? _cpdFlashData.message : _cpdFlash);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', _showCpdFlash, { once: true });
    else _showCpdFlash();
  }
} catch (e) {}

function cpdArchiveProfileHtml(item) {
  var isActive = String(item.status || '').toLowerCase() === 'active';
  var avatar = item.avatar
    ? '<img src="' + cpdEsc(item.avatar) + '" alt="" style="width:100%;height:100%;object-fit:cover;">'
    : cpdEsc(item.initials || (item.name || '?').split(/\s+/).map(function (part) { return part.charAt(0); }).join('').slice(0, 2).toUpperCase());
  return '<div style="display:flex;align-items:center;gap:12px;margin:4px 0 20px;">' +
    '<div style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">' + avatar + '</div>' +
    '<div style="display:flex;flex-direction:column;gap:2px;min-width:0;">' +
      '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;color:#71717a;">Registered client</span>' +
      '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;overflow-wrap:anywhere;">' + cpdEsc(item.name || 'Unnamed resident') + '</span>' +
    '</div>' +
    '<span style="margin-left:auto;font-size:13px;font-weight:500;padding:4px 12px;border-radius:6px;white-space:nowrap;background:' + (isActive ? '#ECFDF3' : '#F1F5F9') + ';color:' + (isActive ? '#027A48' : '#64748b') + ';border:1px solid ' + (isActive ? '#D1FADF' : '#E2E8F0') + ';">' + cpdEsc(item.status || 'Unknown') + '</span>' +
  '</div>' +
  '<div class="info-card-head"><h3 style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:0;">Client Information</h3></div>' +
  '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;padding-bottom:8px;">' +
    infoRow('', 'Resident ID', item.ref || '-') +
    infoRow('', 'Barangay', item.brgy || '-') +
    infoRow('', 'Full Name', item.name || '-') +
    infoRow('', 'Address', item.address || '-') +
    infoRow('', 'Email Address', item.email || '-') +
    infoRow('', 'Registration Date', item.date || '-') +
    infoRow('', 'Mobile Number', item.phone || '-') +
  '</div>';
}

/* Archive — review each selected client profile before applying the bulk action */
function cpdBulkArchive() {
  var items = cpdSelectedClients();
  if (!items.length) { cpdToast('Select at least one client first.', true); return; }
  cpdSeq = { type: 'archive', queue: items.slice(), total: items.length, applied: [], failed: 0, processing: false };
  cpdShowArchiveStep();
}
window.cpdBulkArchive = cpdBulkArchive;

function cpdShowArchiveStep() {
  if (!cpdSeq || !cpdSeq.queue.length) { cpdFinishArchive(); return; }
  var seq = cpdSeq;
  var item = seq.queue[0];
  var index = seq.total - seq.queue.length + 1;
  var profile = document.getElementById('archiveClientsBulkProfile');
  var description = document.getElementById('archiveClientsBulkDescription');
  var confirm = document.getElementById('archiveClientsBulkConfirm');
  var cancel = document.getElementById('archiveClientsBulkCancel');
  var close = document.getElementById('archiveClientsBulkClose');
  if (profile) profile.innerHTML = cpdArchiveProfileHtml(item);
  if (description) {
    description.textContent = 'Review ' + (item.name || 'this resident') + ' before archiving. ' +
      (seq.total === 1 ? '1 client selected.' : seq.total + ' clients selected.') +
      (seq.total > 1 ? ' Client ' + index + ' of ' + seq.total + '.' : '');
  }
  if (confirm) { confirm.textContent = 'Archive this client'; confirm.disabled = false; confirm.onclick = cpdRunBulkArchive; }
  if (cancel) { cancel.disabled = false; cancel.onclick = cpdAbort; }
  if (close) { close.disabled = false; close.onclick = cpdAbort; }
  openModal('archiveClientsBulkModal');
}

function cpdRunBulkArchive() {
  var seq = cpdSeq;
  if (!seq || seq.type !== 'archive' || seq.processing) return;
  seq.processing = true;
  var description = document.getElementById('archiveClientsBulkDescription');
  var confirm = document.getElementById('archiveClientsBulkConfirm');
  var cancel = document.getElementById('archiveClientsBulkCancel');
  var close = document.getElementById('archiveClientsBulkClose');
  var item = seq.queue[0];
  if (confirm) confirm.disabled = true;
  if (cancel) cancel.disabled = true;
  if (close) close.disabled = true;
  if (confirm) confirm.textContent = 'Archiving...';
  if (description) description.textContent = 'Archiving ' + (item.name || 'client') + '...';
  var fd = new FormData();
  fd.append('action', 'archive_client');
  fd.append('user_id', item.id);
  fetch('client-database-handler.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) { if (data && data.success) seq.applied.push(item); else seq.failed++; })
    .catch(function () { seq.failed++; })
    .then(function () {
      if (cpdSeq !== seq) return;
      seq.processing = false;
      seq.queue.shift();
      if (seq.queue.length) cpdShowArchiveStep();
      else cpdFinishArchive();
    });
}

function cpdFinishArchive() {
  if (!cpdSeq) return;
  var seq = cpdSeq;
  cpdSeq = null;
  closeModal('archiveClientsBulkModal');
  cpdFlash(seq.applied.length + ' of ' + seq.total + ' client(s) archived.' + (seq.failed ? ' ' + seq.failed + ' failed.' : ''), 'Clients Archived');
}

/* Edit — sequential editClientModal steps (form save advances the sequence) */
function cpdBulkEdit() {
  var items = cpdSelectedClients();
  if (!items.length) { cpdToast('Select at least one client first.', true); return; }
  cpdSeq = { type: 'edit', queue: items.slice(), total: items.length, applied: [], failed: 0 };
  var root = document.getElementById('editClientModal');
  var descEl = root ? root.querySelector('.sd-dialog-description') : null;
  var closeBtn = root ? root.querySelector('.sd-dialog-close') : null;
  var cancelBtn = root ? root.querySelector('.sd-dialog-footer .sd-btn-outline') : null;
  if (!cpdEditOrig && descEl) {
    cpdEditOrig = { html: descEl.innerHTML, close: closeBtn ? closeBtn.onclick : null, cancel: cancelBtn ? cancelBtn.onclick : null };
  }
  if (closeBtn) closeBtn.onclick = function () { closeModal('editClientModal'); cpdAbort(); };
  if (cancelBtn) cancelBtn.onclick = function () { closeModal('editClientModal'); cpdAbort(); };
  window.cpdEditAdvance = cpdEditAdvanceFn;
  cpdShowEditStep();
}
window.cpdBulkEdit = cpdBulkEdit;

function cpdShowEditStep() {
  if (!cpdSeq || !cpdSeq.queue.length) { cpdFinishEdit(); return; }
  var item = cpdSeq.queue[0];
  var idx = cpdSeq.total - cpdSeq.queue.length + 1;
  openEditClient(item.id);
  var root = document.getElementById('editClientModal');
  var descEl = root ? root.querySelector('.sd-dialog-description') : null;
  if (descEl && cpdEditOrig) {
    descEl.innerHTML = cpdEditOrig.html + (cpdSeq.total > 1 ? ' Editing ' + idx + ' of ' + cpdSeq.total + ' selected clients.' : '');
  }
}

function cpdEditAdvanceFn() {
  if (!cpdSeq || cpdSeq.type !== 'edit') return;
  var item = cpdSeq.queue.shift();
  if (item) cpdSeq.applied.push(item);
  if (cpdSeq.queue.length) { cpdShowEditStep(); return; }
  cpdFinishEdit();
}

function cpdFinishEdit() {
  if (!cpdSeq) return;
  var seq = cpdSeq;
  cpdSeq = null;
  window.cpdEditAdvance = null;
  cpdRestoreEdit();
  closeModal('editClientModal');
  cpdFlash(seq.applied.length + ' of ' + seq.total + ' client(s) updated.');
}

function cpdRestoreEdit() {
  if (!cpdEditOrig) return;
  var root = document.getElementById('editClientModal');
  var descEl = root ? root.querySelector('.sd-dialog-description') : null;
  var closeBtn = root ? root.querySelector('.sd-dialog-close') : null;
  var cancelBtn = root ? root.querySelector('.sd-dialog-footer .sd-btn-outline') : null;
  if (descEl) descEl.innerHTML = cpdEditOrig.html;
  if (closeBtn && cpdEditOrig.close) closeBtn.onclick = cpdEditOrig.close;
  if (cancelBtn && cpdEditOrig.cancel) cancelBtn.onclick = cpdEditOrig.cancel;
  cpdEditOrig = null;
}

/* Cancel/abort a running bulk sequence (Cancel button, backdrop click, or Escape) */
function cpdAbort() {
  if (!cpdSeq) return;
  var seq = cpdSeq;
  if (seq.type === 'archive' && seq.processing) return;
  cpdSeq = null;
  if (seq.type === 'archive') closeModal('archiveClientsBulkModal');
  if (seq.type === 'edit') { window.cpdEditAdvance = null; cpdRestoreEdit(); }
  if (seq.applied && seq.applied.length) cpdFlash(seq.applied.length + ' change(s) applied before cancelling.');
  else cpdToast('Cancelled.');
}

// Treat external closes (backdrop click / Escape) as sequence cancellation
document.addEventListener('click', function (e) {
  if (!cpdSeq || !e.target) return;
  if (cpdSeq.type === 'archive' && e.target.id === 'archiveClientsBulkModal') {
    if (cpdSeq.processing) { e.preventDefault(); e.stopPropagation(); return; }
    cpdAbort();
  }
  else if (cpdSeq.type === 'edit' && e.target.id === 'editClientModal') cpdAbort();
}, true);
document.addEventListener('keydown', function (e) {
  if (!cpdSeq || e.key !== 'Escape') return;
  if (cpdSeq.type === 'archive') {
    if (cpdSeq.processing) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    cpdAbort();
  }
  else { closeModal('editClientModal'); cpdAbort(); }
}, true);
/* ── CPD bulk END ── */