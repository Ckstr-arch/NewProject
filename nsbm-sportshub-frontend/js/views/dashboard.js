import { api } from '../services/api.js';
import { store } from '../store.js';
import { escape, renderTable, statusBadge } from '../components/table.js';

const date = value => new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
const metricIcons = [
  '<path d="M12 3l8 4v6c0 4-8 8-8 8s-8-4-8-8V7l8-4z"/><path d="M8 12l3 3 5-6"/>',
  '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0112 0v3M16 5a3 3 0 010 6m2 4a5 5 0 013 6"/>',
  '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18"/>',
  '<path d="M6 3h12v5a6 6 0 01-12 0V3zM6 5H3v3a4 4 0 004 4m11-7h3v3a4 4 0 01-4 4M12 14v6m-4 1h8"/>'
];

export async function render(container) {
  const user = store.get().user;
  const admin = user.role === 'admin';
  const [metrics, activity, upcoming, participation] = await Promise.all([
    api.metrics(), api.activity(), api.upcoming(), admin ? Promise.resolve({ data: [] }) : api.participation({ limit: 5 })
  ]);
  const stats = [
    ['Active clubs', metrics.data.activeClubs, 'Find your community', '#/clubs'],
    [admin ? 'Memberships' : 'My memberships', metrics.data.memberships, admin ? 'Across our sports community' : 'Your sporting journey', '#/memberships'],
    ['Upcoming events', metrics.data.upcomingEvents, 'Make time for your game', '#/schedules'],
    [admin ? 'Pending bookings' : 'My pending bookings', metrics.data.pendingBookings, admin ? 'Requests awaiting review' : 'Keep track of your requests', '#/bookings']
  ];
  const activities = activity.data || [];
  const events = upcoming.data || [];
  const highest = Math.max(...stats.map(([, count]) => Number(count) || 0), 1);
  container.innerHTML = `
    <div class="page-heading"><div><p class="eyebrow">YOUR CAMPUS. YOUR GAME.</p><h1>Dashboard</h1><p class="muted">A little movement makes a big difference.</p></div><a class="btn btn-secondary" href="#/schedules">View schedule <span aria-hidden="true">→</span></a></div>
    <section class="hero-card" aria-labelledby="welcome-title">
      <div class="hero-copy"><span class="eyebrow">THE NEXT CHAPTER STARTS HERE</span><h2 id="welcome-title">${admin ? 'Bring the campus<br>together through sport.' : `Good to see you,<br>${escape(user.name.split(' ')[0])}.`}</h2><p>${admin ? 'Support your teams, keep the action moving, and make every student part of the game.' : 'Find your team. Build your confidence. Make your next campus memory on the court.'}</p><a href="#/clubs" class="btn btn-primary">Explore sports clubs <span aria-hidden="true">→</span></a></div>
      <div class="hero-mark" aria-hidden="true"><svg viewBox="0 0 300 230" fill="none"><circle cx="175" cy="115" r="94" stroke="currentColor" stroke-width="1" opacity=".2"/><circle cx="175" cy="115" r="70" stroke="currentColor" stroke-width="1" opacity=".3"/><path d="M56 170L134 48h52l-47 75h90l-84 68H96l48-38H67" fill="currentColor"/><path d="M33 201h230M50 24h214" stroke="currentColor" opacity=".2"/><circle cx="255" cy="46" r="8" fill="currentColor"/><path d="M34 82h25M46 70v25" stroke="currentColor" stroke-width="2"/></svg><span>PLAY. CONNECT. GROW.</span></div>
    </section>
    <div class="metric-grid">${stats.map(([label, count, detail, link], i) => `<a class="metric-card" href="${link}"><div class="metric-top"><span class="metric-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">${metricIcons[i]}</svg></span><span aria-hidden="true">→</span></div><strong>${escape(count ?? 0)}</strong><span>${escape(label)}</span><small class="muted">${escape(detail)}</small></a>`).join('')}</div>
    <div class="dashboard-grid">
      <section class="card"><div class="section-heading"><div><p class="eyebrow">MAKE YOUR NEXT MOVE</p><h2>Coming up next</h2></div><a href="#/schedules" class="text-link">View all →</a></div><div class="event-list">${events.length ? events.slice(0, 4).map(event => `<article class="event-item"><div class="event-date"><strong>${escape(new Date(event.startsAt).getDate())}</strong><span>${escape(new Intl.DateTimeFormat(undefined, { month: 'short' }).format(new Date(event.startsAt)))}</span></div><div><h3>${escape(event.title)}</h3><p class="muted">${escape(event.clubName)} · ${escape(event.location)}</p><small>${escape(date(event.startsAt))}</small></div>${statusBadge(event.type)}</article>`).join('') : '<p class="empty-state">No upcoming events. Check back soon.</p>'}</div></section>
      <section class="card"><div class="section-heading"><div><p class="eyebrow">THE CAMPUS PULSE</p><h2>Recent activity</h2></div><span class="badge">${activities.length} updates</span></div><div class="activity-list">${activities.length ? activities.slice(0, 5).map(item => `<article class="activity-item"><span class="activity-dot" aria-hidden="true"></span><div><h3>${escape(item.title)}</h3><p class="muted">${escape(item.description || '')}</p><small class="muted">${escape(date(item.createdAt))}</small></div></article>`).join('') : '<p class="empty-state">Your latest updates will appear here.</p>'}</div></section>
    </div>
    <section class="card"><div class="section-heading"><div><p class="eyebrow">AT A GLANCE</p><h2>${admin ? 'SportsHub snapshot' : 'Your campus snapshot'}</h2></div><span class="muted">Current records</span></div><div class="mini-chart" role="img" aria-label="${escape(stats.map(([name, count]) => `${name}: ${count ?? 0}`).join('; '))}">${stats.map(([label, count]) => `<div class="bar-row"><span>${escape(label)}</span><div class="bar-track"><div class="bar-fill" style="width:${Math.round((Number(count) || 0) / highest * 100)}%"></div></div><strong>${escape(count ?? 0)}</strong></div>`).join('')}</div></section>
    ${!admin ? `<section class="card"><div class="section-heading"><div><p class="eyebrow">EVERY SESSION COUNTS</p><h2>My participation</h2></div><a href="#/memberships" class="text-link">My sporting journey →</a></div>${renderTable([{ label: 'Event', key: 'event' }, { label: 'Sport', key: 'sport' }, { label: 'Date', render: row => escape(date(row.date)) }, { label: 'Status', render: row => statusBadge(row.status) }], participation.data, { empty: 'Your participation history will appear here as you get involved.' })}</section>` : ''}
  `;
}
