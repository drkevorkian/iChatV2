/**
 * Sentinel Chat Platform - Security Configuration
 * 
 * Security-related configuration including encryption keys,
 * JWT secrets, and API rate limits.
 */

export const securityConfig = () => ({
  security: {
    jwtSecret: process.env.JWT_SECRET || 'change-me-in-production',
    jwtExpiresIn: process.env.JWT_EXPIRES_IN || '24h',
    encryptionKey: process.env.ENCRYPTION_KEY || 'change-me-in-production',
    apiSecret: process.env.API_SHARED_SECRET || 'change-me-now',
    bcryptRounds: parseInt(process.env.BCRYPT_ROUNDS || '10', 10),
    rateLimit: {
      windowMs: parseInt(process.env.RATE_LIMIT_WINDOW_MS || '60000', 10),
      max: parseInt(process.env.RATE_LIMIT_MAX || '100', 10),
    },
  },
});

