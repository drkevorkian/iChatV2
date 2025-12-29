/**
 * Sentinel Chat Platform - Database Configuration
 * 
 * PostgreSQL database configuration with connection pooling.
 * Uses TypeORM for database access.
 */

export const databaseConfig = () => ({
  database: {
    type: 'postgres',
    host: process.env.DB_HOST || 'localhost',
    port: parseInt(process.env.DB_PORT || '5432', 10),
    username: process.env.DB_USER || 'sentinel',
    password: process.env.DB_PASSWORD || 'sentinel',
    database: process.env.DB_NAME || 'sentinel_chat',
    synchronize: process.env.NODE_ENV === 'development',
    logging: process.env.NODE_ENV === 'development',
    entities: [__dirname + '/../**/*.entity{.ts,.js}'],
    migrations: [__dirname + '/../migrations/*{.ts,.js}'],
    ssl: process.env.DB_SSL === 'true' ? { rejectUnauthorized: false } : false,
    extra: {
      max: 20, // Maximum number of connections in pool
      connectionTimeoutMillis: 2000,
    },
  },
});

