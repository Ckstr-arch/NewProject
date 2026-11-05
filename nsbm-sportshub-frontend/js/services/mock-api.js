import { CONFIG } from '../config.js';
import { ApiError } from './http.js';

// This is a deliberately inspectable demo simulator, never authentication security.
const clone = value => JSON.parse(JSON.stringify(value));
const ok = (data, message = 'Operation completed successfully') => ({ success: true, data: clone(data), message });
const fail = (message, status = 422, errors = {}) => { throw new ApiError(message, status, errors); };
const iso = (day, hour = 9) => { const d = new Date(); d.setUTCHours(hour, 0, 0, 0); d.setUTCDate(d.getUTCDate() + day); return d.toISOString(); };
const sports = ['Cricket', 'Basketball', 'Football', 'Badminton', 'Swimming', 'Tennis', 'Rugby', 'Volleyball', 'Athletics', 'Chess', 'Table Tennis', 'Netball'];
function seed() {
  const users = [
    { id: 1, name: 'Kavindi Perera', email: 'student@example.test', role: 'student', studentId: 'NSBM-2026-001', faculty: 'Computing', notifications: true },
    { id: 2, name: 'Sports Administrator', email: 'admin@example.test', role: 'admin', studentId: null, faculty: null, notifications: true },
    { id: 3, name: 'Dilan Fernando', email: 'dilan@example.test', role: 'student', studentId: 'NSBM-2026-002', faculty: 'Business', notifications: false },
    { id: 4, name: 'Amani Silva', email: 'amani@example.test', role: 'student', studentId: 'NSBM-2026-003', faculty: 'Engineering', notifications: true }
  ];
  const clubs = sports.map((sport, i) => ({ id: i + 1, name: `${sport} Club`, sport, description: `Train, compete and build friendships with the NSBM ${sport.toLowerCase()} community. All experience levels are welcome.`, coach: ['Nimal Jayasinghe', 'Tharushi Silva', 'Kasun Perera'][i % 3], location: ['University Sports Ground', 'Indoor Sports Complex', 'Recreation Centre'][i % 3], capacity: 40 + i * 5 }));
  const schedules = Array.from({ length: 18 }, (_, i) => ({ id: i + 1, clubId: i % 12 + 1, title: `${sports[i % 12]} ${i % 3 === 0 ? 'team practice' : 'open training'}`, type: i % 5 === 0 ? 'match' : i % 7 === 0 ? 'event' : 'training', startsAt: iso(i + 1, 9 + i % 7), endsAt: iso(i + 1, 11 + i % 7), location: clubs[i % 12].location }));
  const tournaments = Array.from({ length: 6 }, (_, i) => ({ id: i + 1, name: ['Interfaculty Cricket Cup', 'Green University Basketball Open', 'NSBM Football Championship', 'Shuttle Challenge', 'Swim Meet', 'Tennis Open'][i], sport: sports[i], description: 'Represent your faculty in our university sporting season. Open to registered NSBM students.', location: clubs[i].location, startsAt: iso(14 + i * 5), endsAt: iso(15 + i * 5, 17), registrationDeadline: iso(10 + i * 5, 23), capacity: 24 + i * 4 }));
  const facilities = [
    { id: 1, name: 'Main Sports Ground', type: 'facility', location: 'Outdoor Sports Zone' },
    { id: 2, name: 'Basketball Court', type: 'facility', location: 'Indoor Sports Complex' },
    { id: 3, name: 'Badminton Court A', type: 'facility', location: 'Indoor Sports Complex' },
    { id: 4, name: 'Swimming Pool', type: 'facility', location: 'Recreation Centre' },
    { id: 5, name: 'Cricket Equipment Kit', type: 'equipment', location: 'Sports Office' },
    { id: 6, name: 'Badminton Racket Set', type: 'equipment', location: 'Sports Office' }
  ];
  const memberships = Array.from({ length: 15 }, (_, i) => ({ id: i + 1, userId: [1, 3, 4][(i + Math.floor(i / 12)) % 3], clubId: i % 12 + 1, status: i < 6 ? 'approved' : i < 12 ? 'pending' : 'rejected', createdAt: iso(-14 + i) }));
  const bookings = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, userId: [1, 3, 4][i % 3], facilityId: i % 6 + 1, startsAt: iso(i + 2, 14), endsAt: iso(i + 2, 16), purpose: 'Faculty team practice and preparation', status: ['pending', 'approved', 'approved', 'rejected', 'cancelled'][i % 5], createdAt: iso(-3) }));
  const registrations = [ { id: 1, userId: 1, tournamentId: 1, createdAt: iso(-2) }, { id: 2, userId: 3, tournamentId: 2, createdAt: iso(-1) } ];
  const matches = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, tournamentId: i % 6 + 1, homeTeam: ['Computing', 'Business', 'Engineering'][i % 3], awayTeam: ['Business', 'Engineering', 'Computing'][i % 3], startsAt: iso(i < 6 ? -7 + i : 16 + i), status: i < 6 ? 'completed' : 'scheduled', homeScore: i < 6 ? 2 + i % 4 : null, awayScore: i < 6 ? i % 3 : null }));
  const participation = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, userId: [1, 3, 4][i % 3], event: `${sports[i % 6]} team training`, sport: sports[i % 6], date: iso(-12 + i), status: 'attended' }));
  return { version: 1, users, clubs, schedules, tournaments, facilities, memberships, bookings, registrations, matches, participation, sessionUserId: null, activity: [{ id: 1, title: 'A new sporting season', description: 'Explore clubs, training sessions and interfaculty competitions.', userId: null, createdAt: iso(-1) }] };
}

function read() {
  try {
    const raw = localStorage.getItem(CONFIG.MOCK_STORAGE_KEY);
    if (!raw) { const fresh = seed(); save(fresh); return fresh; }
    const data = JSON.parse(raw);
    if (data.version !== 1 || !Array.isArray(data.users) || !Array.isArray(data.clubs)) fail('Demo data is incompatible. Reset demo data in Settings.', 503);
    return data;
  } catch (error) {
    if (error instanceof ApiError) throw error;
    fail('Demo storage is unavailable or damaged. Allow local storage, or reset demo data.', 503);
  }
}
function save(data) {
  try { localStorage.setItem(CONFIG.MOCK_STORAGE_KEY, JSON.stringify(data)); }
  catch { fail('Demo changes could not be saved. Your browser storage may be full or blocked.', 503); }
}
function userOf(db) { return db.users.find(u => u.id === db.sessionUserId) || null; }
function requireUser(db, role) {
  const user = userOf(db);
  if (!user) fail('Please sign in to continue.', 401);
  if (role && user.role !== role) fail('You do not have permission to perform this action.', 403);
  return user;
}
function record(db, collection, id) {
  const item = db[collection].find(row => row.id === Number(id));
  if (!item) fail('This record was not found.', 404);
  return item;
}
const nextId = items => Math.max(0, ...items.map(row => row.id)) + 1;
function enrich(db, collection, value) {
  const row = { ...value };
  if ('userId' in row) row.userName = db.users.find(u => u.id === row.userId)?.name || 'Former student';
  if ('clubId' in row) { const club = db.clubs.find(c => c.id === row.clubId); row.clubName = club?.name || 'Former club'; if (collection === 'schedules') row.sport = club?.sport || ''; }
  if ('tournamentId' in row) { const tournament = db.tournaments.find(t => t.id === row.tournamentId); row.tournamentName = tournament?.name || 'Former tournament'; if (collection === 'matches') row.sport = tournament?.sport || ''; }
  if ('facilityId' in row) { const facility = db.facilities.find(f => f.id === row.facilityId); row.facilityName = facility?.name || 'Former facility'; row.type = facility?.type || 'facility'; }
  if (collection === 'tournaments') row.registrationCount = db.registrations.filter(r => r.tournamentId === row.id).length;
  return row;
}
const listFields = {
  clubs: ['id', 'name', 'sport', 'description', 'coach', 'location', 'capacity'],
  schedules: ['id', 'clubId', 'clubName', 'sport', 'title', 'type', 'startsAt', 'endsAt', 'location'],
  tournaments: ['id', 'name', 'sport', 'description', 'location', 'startsAt', 'endsAt', 'registrationDeadline', 'capacity', 'registrationCount'],
  matches: ['id', 'tournamentId', 'tournamentName', 'sport', 'homeTeam', 'awayTeam', 'startsAt', 'homeScore', 'awayScore', 'status'],
  facilities: ['id', 'name', 'type', 'location'],
  memberships: ['id', 'userId', 'userName', 'clubId', 'clubName', 'status', 'createdAt'],
  bookings: ['id', 'userId', 'userName', 'facilityId', 'facilityName', 'type', 'startsAt', 'endsAt', 'purpose', 'status', 'createdAt'],
  registrations: ['id', 'userId', 'userName', 'tournamentId', 'tournamentName', 'createdAt'],
  participation: ['id', 'userId', 'event', 'sport', 'date', 'status'],
  'report-memberships': ['clubName', 'pending', 'approved', 'rejected'],
  'report-participation': ['sport', 'attended', 'registered'],
  'report-tournaments': ['name', 'registrations', 'capacity'],
  'report-bookings': ['facilityName', 'pending', 'approved', 'rejected', 'cancelled']
};
function list(items, query = {}, resource) {
  const validFields = listFields[resource], errors = {};
  if (!query || typeof query !== 'object' || Array.isArray(query)) fail('Supply valid list parameters.', 422);
  const q = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined && value !== null && value !== ''));
  for (const [key, value] of Object.entries(q)) {
    if (!['page', 'limit', 'search', 'sort', 'order', 'sport', 'status', 'type', 'clubId', 'tournamentId', 'facilityId'].includes(key)) errors[key] = ['Unknown query parameter.'];
    else if (!['string', 'number'].includes(typeof value)) errors[key] = ['Use a text or numeric query value.'];
    else if (['sport', 'status', 'type', 'clubId', 'tournamentId', 'facilityId'].includes(key) && !validFields.includes(key)) errors[key] = ['This filter does not apply to this resource.'];
  }
  const page = Number(q.page ?? 1), limit = Number(q.limit ?? 10);
  if (!Number.isInteger(page) || page < 1) errors.page = ['Use a positive whole-number page.'];
  if (!Number.isInteger(limit) || limit < 1 || limit > 100) errors.limit = ['Use a limit between 1 and 100.'];
  if (q.sort && !validFields.includes(q.sort)) errors.sort = ['Choose a field belonging to this resource.'];
  if (q.order && !['asc', 'desc'].includes(q.order)) errors.order = ['Choose asc or desc.'];
  const statuses = { memberships: ['pending', 'approved', 'rejected'], bookings: ['pending', 'approved', 'rejected', 'cancelled'], matches: ['scheduled', 'completed'], participation: ['attended', 'registered'] };
  if (q.status && !statuses[resource]?.includes(q.status)) errors.status = ['Choose a valid resource status.'];
  if (q.type && !(resource === 'schedules' ? ['training', 'match', 'event'] : ['facility', 'equipment']).includes(q.type)) errors.type = ['Choose a valid resource type.'];
  for (const key of ['clubId', 'tournamentId', 'facilityId']) if (q[key] !== undefined && (!Number.isInteger(Number(q[key])) || Number(q[key]) < 1)) errors[key] = ['Use a positive integer identifier.'];
  if (Object.keys(errors).length) fail('Please check your list filters.', 422, errors);
  let rows = clone(items);
  if (q.search) rows = rows.filter(row => Object.values(row).some(v => String(v).toLowerCase().includes(String(q.search).toLowerCase())));
  for (const key of ['sport', 'status', 'type', 'clubId', 'tournamentId', 'facilityId']) if (q[key]) rows = rows.filter(row => String(row[key]) === String(q[key]));
  if (q.sort) {
    const direction = q.order === 'desc' ? -1 : 1;
    rows.sort((a, b) => (typeof a[q.sort] === 'number' ? a[q.sort] - b[q.sort] : String(a[q.sort] ?? '').localeCompare(String(b[q.sort] ?? ''))) * direction);
  }
  const total = rows.length;
  return { success: true, data: rows.slice((page - 1) * limit, page * limit), pagination: { page, limit, total, totalPages: Math.ceil(total / limit) } };
}
function addActivity(db, user, title, description) { db.activity.unshift({ id: nextId(db.activity), title, description, userId: user.id, createdAt: new Date().toISOString() }); db.activity = db.activity.slice(0, 100); }
const fields = {
  clubs: ['name', 'sport', 'description', 'coach', 'location', 'capacity'],
  schedules: ['clubId', 'title', 'type', 'startsAt', 'endsAt', 'location'],
  tournaments: ['name', 'sport', 'description', 'location', 'startsAt', 'endsAt', 'registrationDeadline', 'capacity'],
  matches: ['tournamentId', 'homeTeam', 'awayTeam', 'startsAt', 'status', 'homeScore', 'awayScore'],
  bookings: ['facilityId', 'startsAt', 'endsAt', 'purpose']
};
function validate(db, collection, input) {
  const body = input && typeof input === 'object' && !Array.isArray(input) ? input : {};
  const errors = {}, clean = {};
  for (const key of Object.keys(body)) if (!fields[collection].includes(key)) errors[key] = ['This field is not editable.'];
  const optionalScores = collection === 'matches' && body.status === 'scheduled';
  for (const key of fields[collection]) {
    const value = body[key];
    if (optionalScores && ['homeScore', 'awayScore'].includes(key)) { clean[key] = null; continue; }
    if (value === undefined || value === null || value === '') { errors[key] = ['This field is required.']; continue; }
    if (['clubId', 'tournamentId', 'facilityId', 'capacity', 'homeScore', 'awayScore'].includes(key)) {
      const n = value, min = key.endsWith('Score') ? 0 : 1, max = key.endsWith('Score') ? 999 : key === 'capacity' ? 1000 : Number.MAX_SAFE_INTEGER;
      if (typeof n !== 'number' || !Number.isInteger(n) || n < min || n > max) errors[key] = [`Enter a whole number between ${min} and ${max}.`]; else clean[key] = n;
    } else if (['startsAt', 'endsAt', 'registrationDeadline'].includes(key)) {
      if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/.test(value) || !Number.isFinite(Date.parse(value))) errors[key] = ['Enter a valid date and time.']; else clean[key] = new Date(value).toISOString();
    } else {
      const text = typeof value === 'string' ? value.trim() : '';
      const max = key === 'description' ? 1000 : key === 'purpose' ? 300 : key === 'sport' ? 60 : 100;
      const min = key === 'description' ? 1 : key === 'purpose' ? 3 : 2;
      if (text.length < min || text.length > max) errors[key] = [`Use ${min}–${max} characters.`]; else clean[key] = text;
    }
  }
  if (clean.endsAt && clean.startsAt && clean.endsAt <= clean.startsAt) errors.endsAt = ['End time must be after start time.'];
  if (collection === 'bookings' && clean.startsAt) {
    if (Date.parse(clean.startsAt) <= Date.now()) errors.startsAt = ['Choose a future start time.'];
    if (Date.parse(clean.endsAt) - Date.parse(clean.startsAt) > 4 * 3600000) errors.endsAt = ['Bookings may last at most four hours.'];
  }
  if (collection === 'tournaments' && clean.registrationDeadline && clean.startsAt && clean.registrationDeadline >= clean.startsAt) errors.registrationDeadline = ['Registration must close before the tournament starts.'];
  if (collection === 'schedules' && clean.type && !['training', 'match', 'event'].includes(clean.type)) errors.type = ['Choose training, match or event.'];
  if (collection === 'matches') {
    if (!['scheduled', 'completed'].includes(clean.status)) errors.status = ['Choose scheduled or completed.'];
    if (clean.homeTeam?.toLowerCase() === clean.awayTeam?.toLowerCase()) errors.awayTeam = ['Choose a different opponent.'];
  }
  if (Object.keys(errors).length) fail('Please check the highlighted fields.', 422, errors);
  if (clean.clubId) record(db, 'clubs', clean.clubId);
  if (clean.tournamentId) record(db, 'tournaments', clean.tournamentId);
  if (clean.facilityId) record(db, 'facilities', clean.facilityId);
  return clean;
}
function statusBody(body, allowed) {
  if (!body || Object.keys(body).some(k => k !== 'status') || !allowed.includes(body.status)) fail('Choose a valid status.', 422, { status: [`Choose ${allowed.join(', ')}.`] });
  return body.status;
}
function conflictBooking(db, booking) { return db.bookings.some(b => b.id !== booking.id && b.facilityId === booking.facilityId && ['pending', 'approved'].includes(b.status) && b.startsAt < booking.endsAt && b.endsAt > booking.startsAt); }
function standings(db, id) {
  const teams = new Map();
  const team = name => { if (!teams.has(name)) teams.set(name, { team: name, played: 0, won: 0, drawn: 0, lost: 0, points: 0 }); return teams.get(name); };
  db.matches.filter(m => m.tournamentId === id).forEach(m => {
    const home = team(m.homeTeam), away = team(m.awayTeam);
    if (m.status !== 'completed') return;
    home.played++; away.played++;
    if (m.homeScore === m.awayScore) { home.drawn++; away.drawn++; home.points++; away.points++; }
    else { const win = m.homeScore > m.awayScore ? home : away, lose = win === home ? away : home; win.won++; win.points += 3; lose.lost++; }
  });
  return [...teams.values()].sort((a, b) => b.points - a.points || a.team.localeCompare(b.team));
}

export const mock = {
  async reset() { const data = seed(); save(data); return ok({ user: null, csrfToken: 'demo-only-not-a-security-token' }, 'Demo data reset. Sign in to start again.'); },
  async request(method, path, body, query = {}) {
    await new Promise(resolve => setTimeout(resolve, CONFIG.MOCK_LATENCY));
    const db = read();
    const sessionData = () => ({ user: userOf(db), csrfToken: 'demo-only-not-a-security-token' });
    if (method === 'GET' && path === '/auth/session') return ok(sessionData());
    if (method === 'POST' && path === '/auth/login') {
      const email = typeof body?.email === 'string' ? body.email.trim().toLowerCase() : '';
      const errors = {};
      if (!body || typeof body !== 'object' || Array.isArray(body)) errors.email = ['Supply email and password.'];
      for (const key of Object.keys(body || {})) if (!['email', 'password'].includes(key)) errors[key] = ['This field is not supported.'];
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = ['Enter a valid email address.'];
      if (typeof body?.password !== 'string' || !body.password) errors.password = ['Enter your password.'];
      if (Object.keys(errors).length) fail('Check your login details.', 422, errors);
      const user = db.users.find(u => u.email === email && (u.id === 1 || u.id === 2));
      const expected = user?.role === 'admin' ? 'Admin123!' : 'Student123!';
      if (!user || body.password !== expected) fail('The email or password is incorrect.', 401);
      db.sessionUserId = user.id; save(db); return ok(sessionData(), 'Welcome back');
    }
    if (method === 'POST' && path === '/auth/logout') { requireUser(db); if (body !== undefined) fail('Logout does not accept a request body.', 422); db.sessionUserId = null; save(db); return ok(sessionData(), 'Signed out'); }
    if (method === 'GET' && path === '/system/health') return ok({ status: 'ok', mode: 'mock', database: 'simulated', message: 'Browser demo only. No PHP or MySQL connection is tested.' });
    const user = requireUser(db);
    const mine = collection => db[collection].filter(r => r.userId === user.id);
    const enriched = (collection, items = db[collection]) => items.map(item => enrich(db, collection, item));
    const commit = (data, message) => { save(db); return ok(data, message); };
    if (path === '/dashboard/metrics' && method === 'GET') return ok({ activeClubs: db.clubs.length, memberships: (user.role === 'admin' ? db.memberships : mine('memberships')).filter(m => m.status === 'approved').length, upcomingEvents: db.schedules.filter(s => Date.parse(s.startsAt) > Date.now()).length, pendingBookings: (user.role === 'admin' ? db.bookings : mine('bookings')).filter(b => b.status === 'pending').length });
    if (path === '/dashboard/activity' && method === 'GET') return ok(db.activity.filter(a => user.role === 'admin' || a.userId === null || a.userId === user.id).slice(0, 8));
    if (path === '/dashboard/upcoming' && method === 'GET') return ok(enriched('schedules', db.schedules.filter(s => Date.parse(s.startsAt) > Date.now()).sort((a, b) => a.startsAt.localeCompare(b.startsAt)).slice(0, 5)));
    if (path === '/users/me') {
      if (method === 'GET') return ok(user);
      if (method === 'PATCH') {
        const errors = {}, allowed = ['name', 'faculty', 'notifications'];
        if (!body || !Object.keys(body).length) errors.name = ['Supply at least one editable field.'];
        for (const key of Object.keys(body || {})) if (!allowed.includes(key)) errors[key] = ['This field is not editable.'];
        if (body?.name !== undefined && (typeof body.name !== 'string' || body.name.trim().length < 2 || body.name.trim().length > 80)) errors.name = ['Use 2–80 characters.'];
        if (body?.faculty !== undefined && body.faculty !== null && (typeof body.faculty !== 'string' || body.faculty.trim().length > 100)) errors.faculty = ['Use at most 100 characters.'];
        if (body?.notifications !== undefined && typeof body.notifications !== 'boolean') errors.notifications = ['Use true or false.'];
        if (Object.keys(errors).length) fail('Please check your profile.', 422, errors);
        for (const key of allowed) if (key in body) user[key] = typeof body[key] === 'string' ? body[key].trim() : body[key];
        return commit(user, 'Profile updated');
      }
    }
    if (path === '/users/me/memberships' && method === 'GET') return list(enriched('memberships', mine('memberships')), query, 'memberships');
    if (path === '/users/me/bookings' && method === 'GET') return list(enriched('bookings', mine('bookings')), query, 'bookings');
    if (path === '/users/me/tournament-registrations' && method === 'GET') return list(enriched('registrations', mine('registrations')), query, 'registrations');
    if (path === '/users/me/participation' && method === 'GET') {
      const registrations = mine('registrations').map(r => { const t = record(db, 'tournaments', r.tournamentId); return { id: 10000 + r.id, userId: user.id, event: t.name, sport: t.sport, date: t.startsAt, status: 'registered' }; });
      return list([...mine('participation'), ...registrations], query, 'participation');
    }
    if (path.startsWith('/reports/') && method === 'GET') {
      requireUser(db, 'admin');
      let rows;
      if (path === '/reports/memberships') rows = db.clubs.map(c => ({ clubName: c.name, ...Object.fromEntries(['pending', 'approved', 'rejected'].map(status => [status, db.memberships.filter(m => m.clubId === c.id && m.status === status).length])) }));
      if (path === '/reports/participation') rows = [...new Set([...db.participation.map(p => p.sport), ...db.tournaments.map(t => t.sport)])].map(sport => ({ sport, attended: db.participation.filter(p => p.sport === sport && p.status === 'attended').length, registered: db.registrations.filter(r => db.tournaments.find(t => t.id === r.tournamentId)?.sport === sport).length }));
      if (path === '/reports/tournaments') rows = db.tournaments.map(t => ({ name: t.name, registrations: db.registrations.filter(r => r.tournamentId === t.id).length, capacity: t.capacity }));
      if (path === '/reports/bookings') rows = db.facilities.map(f => ({ facilityName: f.name, ...Object.fromEntries(['pending', 'approved', 'rejected', 'cancelled'].map(status => [status, db.bookings.filter(b => b.facilityId === f.id && b.status === status).length])) }));
      if (rows) return list(rows, query, `report-${path.split('/').at(-1)}`);
    }
    let match = path.match(/^\/tournaments\/(\d+)\/standings$/);
    if (match && method === 'GET') { const t = record(db, 'tournaments', match[1]); return ok(standings(db, t.id)); }
    match = path.match(/^\/tournaments\/(\d+)\/registrations$/);
    if (match && method === 'POST') {
      requireUser(db, 'student');
      if (body !== undefined) fail('Tournament registration does not accept a request body.', 422);
      const t = record(db, 'tournaments', match[1]);
      if (db.registrations.some(r => r.tournamentId === t.id && r.userId === user.id)) fail('You are already registered for this tournament.', 409);
      if (Date.parse(t.registrationDeadline) <= Date.now()) fail('Registration has closed.', 409);
      if (db.registrations.filter(r => r.tournamentId === t.id).length >= t.capacity) fail('This tournament is full.', 409);
      const item = { id: nextId(db.registrations), userId: user.id, tournamentId: t.id, createdAt: new Date().toISOString() };
      db.registrations.push(item); addActivity(db, user, 'Tournament registration', t.name); return commit(enrich(db, 'registrations', item), 'Tournament registration confirmed');
    }
    if (path === '/memberships') {
      if (method === 'GET') { requireUser(db, 'admin'); return list(enriched('memberships'), query, 'memberships'); }
      if (method === 'POST') {
        requireUser(db, 'student');
        if (!body || Object.keys(body).some(k => k !== 'clubId') || typeof body.clubId !== 'number' || !Number.isInteger(body.clubId) || body.clubId < 1) fail('Choose a club.', 422, { clubId: ['Choose an existing club.'] });
        const club = record(db, 'clubs', body.clubId);
        if (db.memberships.some(m => m.userId === user.id && m.clubId === club.id && ['pending', 'approved'].includes(m.status))) fail('You already have an active application or membership for this club.', 409);
        let item = db.memberships.find(m => m.userId === user.id && m.clubId === club.id);
        if (item) { item.status = 'pending'; item.createdAt = new Date().toISOString(); }
        else { item = { id: nextId(db.memberships), userId: user.id, clubId: club.id, status: 'pending', createdAt: new Date().toISOString() }; db.memberships.push(item); } addActivity(db, user, 'Membership requested', club.name); return commit(enrich(db, 'memberships', item), 'Membership request sent');
      }
    }
    match = path.match(/^\/memberships\/(\d+)\/status$/);
    if (match && method === 'PATCH') {
      requireUser(db, 'admin'); const item = record(db, 'memberships', match[1]), status = statusBody(body, ['approved', 'rejected']);
      if (item.status !== 'pending') fail('Only pending memberships can be reviewed.', 409);
      const club = record(db, 'clubs', item.clubId);
      if (status === 'approved' && db.memberships.filter(m => m.clubId === club.id && m.status === 'approved').length >= club.capacity) fail('This club is full.', 409);
      item.status = status; addActivity(db, user, `Membership ${status}`, club.name); return commit(enrich(db, 'memberships', item), 'Membership reviewed');
    }
    if (path === '/bookings') {
      if (method === 'GET') { requireUser(db, 'admin'); return list(enriched('bookings'), query, 'bookings'); }
      if (method === 'POST') {
        requireUser(db, 'student'); const clean = validate(db, 'bookings', body);
        if (conflictBooking(db, clean)) fail('This resource is already reserved during that time.', 409, { startsAt: ['Choose another time or resource.'] });
        const item = { ...clean, id: nextId(db.bookings), userId: user.id, status: 'pending', createdAt: new Date().toISOString() };
        db.bookings.push(item); addActivity(db, user, 'Booking requested', record(db, 'facilities', item.facilityId).name); return commit(enrich(db, 'bookings', item), 'Booking request sent');
      }
    }
    match = path.match(/^\/bookings\/(\d+)\/status$/);
    if (match && method === 'PATCH') {
      const item = record(db, 'bookings', match[1]);
      if (user.role !== 'admin' && ['approved', 'rejected'].includes(body?.status)) fail('Only administrators may review bookings.', 403);
      const status = statusBody(body, user.role === 'admin' ? ['approved', 'rejected', 'cancelled'] : ['cancelled']);
      if (user.role !== 'admin' && item.userId !== user.id) fail('You can only cancel your own booking.', 403);
      if (!['pending', 'approved'].includes(item.status) || (user.role !== 'admin' && Date.parse(item.startsAt) <= Date.now())) fail('This booking can no longer be changed.', 409);
      if (status === 'approved' && conflictBooking(db, item)) fail('An overlapping reservation already exists.', 409);
      item.status = status; addActivity(db, user, `Booking ${status}`, record(db, 'facilities', item.facilityId).name); return commit(enrich(db, 'bookings', item), 'Booking updated');
    }
    if (path === '/facilities' && method === 'GET') return list(db.facilities, query, 'facilities');
    match = path.match(/^\/(clubs|schedules|tournaments|matches)(?:\/(\d+))?$/);
    if (match) {
      const [, collection, id] = match;
      if (method === 'GET') {
        if (id) return ok(enrich(db, collection, record(db, collection, id)));
        return list(enriched(collection), query, collection);
      }
      requireUser(db, 'admin');
      if (method === 'POST' && !id) {
        const clean = validate(db, collection, body), item = { ...clean, id: nextId(db[collection]) };
        db[collection].push(item); addActivity(db, user, `${collection.slice(0, -1)} created`, item.name || item.title || `${item.homeTeam} vs ${item.awayTeam}`); return commit(enrich(db, collection, item), 'Record created');
      }
      if (method === 'PUT' && id) {
        const item = record(db, collection, id), clean = validate(db, collection, body);
        if (collection === 'clubs' && clean.capacity < db.memberships.filter(m => m.clubId === item.id && m.status === 'approved').length) fail('Capacity cannot be less than current approved membership.', 409);
        if (collection === 'tournaments' && clean.capacity < db.registrations.filter(r => r.tournamentId === item.id).length) fail('Capacity cannot be less than existing registrations.', 409);
        Object.assign(item, clean); addActivity(db, user, `${collection.slice(0, -1)} updated`, item.name || item.title || item.homeTeam); return commit(enrich(db, collection, item), 'Record updated');
      }
      if (method === 'DELETE' && id && collection !== 'matches') {
        const item = record(db, collection, id);
        if (collection === 'clubs' && (db.memberships.some(m => m.clubId === item.id) || db.schedules.some(s => s.clubId === item.id))) fail('This club has memberships or schedules. Remove related records before deleting it.', 409);
        if (collection === 'tournaments' && (db.registrations.some(r => r.tournamentId === item.id) || db.matches.some(m => m.tournamentId === item.id))) fail('This tournament has registrations or fixtures and cannot be deleted.', 409);
        db[collection] = db[collection].filter(row => row.id !== item.id); addActivity(db, user, `${collection.slice(0, -1)} deleted`, item.name || item.title); return commit(null, 'Record deleted');
      }
    }
    fail('This endpoint was not found.', 404);
  }
};
