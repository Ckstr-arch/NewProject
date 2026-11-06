import { store } from '../../store.js';
export function can(permission) {
  const permissions = store.get().permissions || [];
  return permissions.some(value => value === '*' || value === permission || value === `${permission.split('.')[0]}.*`);
}
export function canEditClub(club) {
  const user = store.get().user;
  return can('clubs.update') && (user.role !== 'club_manager' || Number(club.manager_id) === Number(user.id));
}
