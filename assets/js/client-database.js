// Client & Pet Database (embedded in dashboard-staff.php#client-pets)
// Expects globals: CLIENTS, PETS, VIEW_OWNER (output by the page before this script)
var currentProfile = null;

var state = { mode:'clients', view:'table', group:'all', quick:'', brgy:'', type:'', petSearch:'', search:'', sort:'newest', page:1, perPage:10, selected:0, sortCol:'', sortDir:'' };

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
  if (col==='brgy') return cpdEsc(c.brgy||'-');
  if (col==='pets') return '<span class="pets-badge">'+c.pets+'</span>';
  if (col==='date') return cpdEsc(c.date);
  if (col==='status') return '<span class="status-pill '+(c.status==='Active'?'status-active':'status-inactive')+'">'+c.status+'</span>';
  return '';
}
function petCellHtml(col, p){
  var v = vaxInfo(p.vax);
  if (col==='name') return '<div class="client-cell"><div class="avatar">'+petPhotoHtml(p)+'</div><div class="client-name">'+cpdEsc(p.name)+'</div></div>';
  if (col==='ref') return '<span class="client-id">'+cpdEsc(p.ref)+'</span>';
  if (col==='owner') return cpdEsc(p.ownerName);
  if (col==='ownerRef') return '<span class="client-id">'+cpdEsc(p.ownerRef||'')+'</span>';
  if (col==='species') return cpdEsc(p.typeLabel);
  if (col==='gender') return cpdEsc(p.gender);
  if (col==='age') return cpdEsc(p.age);
  if (col==='vax') return '<span class="vax-pill '+v.c+'">'+v.l+'</span>';
  if (col==='brgy') return cpdEsc(p.brgy||'-');
  if (col==='date') return cpdEsc(p.date);
  return '';
}
function rowHtml(mode, item){
  var selCls = state.selected===item.id ? 'selected' : '';
  var clickAttr = mode==='clients' ? " onclick=\"selectClient("+item.id+")\"" : " onclick=\"selectPet("+item.ownerId+")\"";
  var html = '<tr data-id="'+item.id+'" class="sd-tr '+selCls+'"'+clickAttr+'>';
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

  var visibleCount = getDefs().length + 1;
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

  box.innerHTML =
    '<div class="profile-header">'+
      '<div class="profile-avatar">'+ (c.avatar ? '<img src="'+cpdEsc(c.avatar)+'" alt="">' : cpdEsc(c.initials)) +'</div>'+
      '<div style="min-width:0;">'+
        '<div class="profile-name-row"><h2>'+cpdEsc(c.full_name)+'</h2><span class="status-pill '+(c.status==='active'?'status-active':'status-inactive')+'">'+(c.status==='active'?'Active':'Inactive')+'</span></div>'+
        '<p class="profile-id">'+cpdEsc(c.reference_id)+'</p>'+
      '</div>'+
      '<button class="close-x" title="Close" onclick="closeProfile()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>'+
    '</div>'+
    '<div class="tabs">'+
      '<button class="tab'+(activeTab==='profile'?' active':'')+'" onclick="renderProfile(\'profile\')">Profile</button>'+
      '<button class="tab'+(activeTab==='pets'?' active':'')+'" onclick="renderProfile(\'pets\')">Pets ('+pets.length+')</button>'+
    '</div>'+
    '<div id="profileTabBody"></div>';

  var body = document.getElementById('profileTabBody');

  if (activeTab==='profile'){
    body.innerHTML =
      '<div class="info-card-head"><h3>Client Information</h3>'+
        '<button class="link-btn" onclick="openEditClient('+c.user_id+')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>'+
      '</div>'+
      '<div class="info-rows">'+infoRow('user','Full Name',c.full_name)+infoRow('mail','Email Address',c.email||'-')+infoRow('phone','Mobile Number',c.phone||'-')+infoRow('home','Barangay',c.barangay_name||'-')+infoRow('home2','Address',c.address||'-')+infoRow('cal','Registration Date',c.registration_date)+infoRow('check','Status',c.status==='active'?'Active':'Inactive')+'</div>';
  } else if (activeTab==='pets'){
    body.innerHTML = pets.length ? pets.map(petCardProfile).join('') : '<p style="color:var(--ink-400); font-size:13px;">No registered pets.</p>';
  }
}

function infoRow(icon, label, value){
  var icons = {
    user:'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    mail:'<path d="M22 6L12 13 2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
    phone:'<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
    home:'<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    home2:'<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    cal:'<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    check:'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'
  };
  return '<div class="info-row"><div class="info-row-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+(icons[icon]||'')+'</svg></div><div><p class="info-row-label">'+label+'</p><p class="info-row-value">'+cpdEsc(value)+'</p></div></div>';
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
}

// ── Add / Edit / Archive ──
function openAddClient(){ openModal('addClientModal'); }

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
  } else if (CLIENTS.length){
    loadClient(CLIENTS[0].id, 'profile');
  }
})();