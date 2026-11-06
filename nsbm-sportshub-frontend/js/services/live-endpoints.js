// The live backend exposes only the operations in the PHP/MySQL specification.
export const liveEndpoints = {
  login: ['POST', '/auth/login'], logout: ['POST', '/auth/logout'], session: ['GET', '/auth/session'],
  metrics: ['GET', '/dashboard/metrics'], health: ['GET', '/system/health'],
  listClubs: ['GET', '/clubs'], getClub: ['GET', '/clubs/:id'], createClub: ['POST', '/clubs'], updateClub: ['PUT', '/clubs/:id'], deleteClub: ['DELETE', '/clubs/:id'],
  listSchedules: ['GET', '/schedules'], getSchedule: ['GET', '/schedules/:id'], createSchedule: ['POST', '/schedules'], updateSchedule: ['PUT', '/schedules/:id'], deleteSchedule: ['DELETE', '/schedules/:id'],
  listTournaments: ['GET', '/tournaments'], getTournament: ['GET', '/tournaments/:id'], createTournament: ['POST', '/tournaments'], updateTournament: ['PUT', '/tournaments/:id'], deleteTournament: ['DELETE', '/tournaments/:id'],
  listBookings: ['GET', '/bookings'], getBooking: ['GET', '/bookings/:id'], createBooking: ['POST', '/bookings'], updateBooking: ['PUT', '/bookings/:id'], deleteBooking: ['DELETE', '/bookings/:id']
};
