/**
 * Sentinel Chat Platform - Access Tier Enum
 * 
 * Defines user access tiers for role-based access control.
 * Used throughout the application to determine permissions.
 */

export enum AccessTier {
  GUEST = 'guest',
  MEMBER = 'member',
  PAID_MEMBER = 'paid-member',
  OWNER = 'owner',
  MODERATOR = 'moderator',
  ADMINISTRATOR = 'administrator',
}

