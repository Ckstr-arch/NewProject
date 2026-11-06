import { api } from '../../services/api.js';
import { store } from '../../store.js';
import { escape, statusBadge } from '../../components/table.js';

export async function render(container) {
  const { data } = await api.metrics();
  const stats = [
    ['Active members', data.active_members, 'Active student accounts', '#/clubs'],
    ['Sports clubs', data.clubs, 'Find your sporting community', '#/clubs'],
    ['Upcoming events', data.upcoming_events_count, 'Plan your next session', '#/schedules'],
    ['Bookings', data.bookings, 'Facility reservations', '#/bookings']
  ];
  const events = data.upcoming_events || [];
  const activity = data.recent_activity || [];
  const weekly = data.weekly_activity || [];
  const maximum = Math.max(1, ...weekly.map(item => Number(item.count) || 0));
  container.innerHTML = `<div class="page-heading"><div><p class="eyebrow">YOUR CAMPUS. YOUR GAME.</p><h1>Dashboard</h1><p class="muted">Your university sports community, connected.</p></div><a class="btn btn-secondary" href="#/schedules">View schedule →</a></div>
    <section class="hero-card"><div class="hero-copy"><span class="eyebrow">MAKE YOUR NEXT MOVE</span><h2>Good to see you,<br>${escape(store.get().user.name.split(' ')[0])}.</h2><p>Find a club, plan a training session, and make time for your game.</p><a class="btn btn-primary" href="#/clubs">Explore sports clubs →</a></div><div class="hero-mark" aria-hidden="true"><svg viewBox="0 0 300 230" fill="none"><circle cx="150" cy="115" r="90" stroke="currentColor" opacity=".3"/><circle cx="150" cy="115" r="65" stroke="currentColor" opacity=".4"/><path d="M100 40h65l-35 65h70l-100 90 30-70H80z" fill="currentColor"/></svg><span>PLAY. CONNECT. GROW.</span></div></section>
    <div class="metric-grid">${stats.map(([label,count,detail,link]) => `<a class="metric-card" href="${link}"><span>${escape(label)}</span><strong>${escape(count ?? 0)}</strong><small class="muted">${escape(detail)}</small></a>`).join('')}</div>
    <div class="dashboard-grid"><section class="card"><div class="section-heading"><div><p class="eyebrow">MAKE YOUR NEXT MOVE</p><h2>Coming up next</h2></div><a href="#/schedules" class="text-link">View all →</a></div><div class="event-list">${events.length ? events.slice(0,5).map(event => `<article class="event-item"><div><h3>${escape(event.event_name || event.name || event.title)}</h3><p class="muted">${escape(event.sport)} · ${escape(event.location || '')}</p><small>${escape(event.event_date || event.start_date || '')} ${escape(event.start_time || '')}</small></div>${statusBadge(event.status)}</article>`).join('') : '<p class="empty-state">No upcoming events.</p>'}</div></section>
    <section class="card"><div class="section-heading"><div><p class="eyebrow">THE CAMPUS PULSE</p><h2>Recent activity</h2></div></div><div class="activity-list">${activity.length ? activity.slice(0,6).map(item => `<article class="activity-item"><span class="activity-dot" aria-hidden="true"></span><div><h3>${escape(item.title || item.event_name || item.name)}</h3><p class="muted">${escape(item.description || '')}</p><small class="muted">${escape(item.created_at || '')}</small></div></article>`).join('') : '<p class="empty-state">No recent activity.</p>'}</div></section></div>
    <section class="card"><div class="section-heading"><div><p class="eyebrow">THIS WEEK</p><h2>Weekly activity</h2></div></div><div class="mini-chart" role="img" aria-label="${escape(weekly.map(item => `${item.label || item.date}: ${item.count}`).join('; '))}">${weekly.length ? weekly.map(item => `<div class="bar-row"><span>${escape(item.label || item.date)}</span><div class="bar-track"><div class="bar-fill" style="width:${Math.round(Math.max(0,Number(item.count)||0)/maximum*100)}%"></div></div><strong>${escape(item.count)}</strong></div>`).join('') : '<p class="muted">No activity this week.</p>'}</div></section>`;
}
