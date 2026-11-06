// Standalone demo endpoint map. The current PHP API map is exported below.
// All paths are relative to CONFIG.API_BASE_URL.
export { liveEndpoints } from './live-endpoints.js';
export const endpoints = {
  login: ['POST', '/auth/login'], logout: ['POST', '/auth/logout'], session: ['GET', '/auth/session'],
  metrics: ['GET', '/dashboard/metrics'], activity: ['GET', '/dashboard/activity'], upcoming: ['GET', '/dashboard/upcoming'],
  listClubs: ['GET', '/clubs'], getClub: ['GET', '/clubs/:id'], createClub: ['POST', '/clubs'], updateClub: ['PUT', '/clubs/:id'], deleteClub: ['DELETE', '/clubs/:id'],
  listMemberships: ['GET', '/memberships'], applyMembership: ['POST', '/memberships'], setMembershipStatus: ['PATCH', '/memberships/:id/status'], myMemberships: ['GET', '/users/me/memberships'],
  listSchedules: ['GET', '/schedules'], createSchedule: ['POST', '/schedules'], updateSchedule: ['PUT', '/schedules/:id'], deleteSchedule: ['DELETE', '/schedules/:id'],
  listTournaments: ['GET', '/tournaments'], getTournament: ['GET', '/tournaments/:id'], createTournament: ['POST', '/tournaments'], updateTournament: ['PUT', '/tournaments/:id'], deleteTournament: ['DELETE', '/tournaments/:id'], registerTournament: ['POST', '/tournaments/:id/registrations'], myRegistrations: ['GET', '/users/me/tournament-registrations'],
  listMatches: ['GET', '/matches'], createMatch: ['POST', '/matches'], updateMatch: ['PUT', '/matches/:id'], standings: ['GET', '/tournaments/:id/standings'],
  listFacilities: ['GET', '/facilities'], listBookings: ['GET', '/bookings'], createBooking: ['POST', '/bookings'], setBookingStatus: ['PATCH', '/bookings/:id/status'], myBookings: ['GET', '/users/me/bookings'],
  participation: ['GET', '/users/me/participation'],
  membershipReport: ['GET', '/reports/memberships'], participationReport: ['GET', '/reports/participation'], tournamentReport: ['GET', '/reports/tournaments'], bookingReport: ['GET', '/reports/bookings'],
  profile: ['GET', '/users/me'], updateProfile: ['PATCH', '/users/me'], health: ['GET', '/system/health']
};
