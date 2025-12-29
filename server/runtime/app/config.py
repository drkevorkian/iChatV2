"""
Sentinel Chat Platform - Configuration Management

Loads configuration from environment variables with sensible defaults.
"""

from pydantic_settings import BaseSettings
from typing import List


class Settings(BaseSettings):
    """Application settings loaded from environment variables"""
    
    # Application
    APP_NAME: str = "Sentinel Chat Runtime"
    DEBUG: bool = False
    HOST: str = "0.0.0.0"
    PORT: int = 8000
    
    # Database (MySQL/MariaDB for temp outbox)
    DB_HOST: str = "127.0.0.1"
    DB_PORT: int = 3306
    DB_NAME: str = "sentinel_temp"
    DB_USER: str = "sentinel"
    DB_PASSWORD: str = "sentinel"
    
    # Primary server (NestJS)
    PRIMARY_SERVER_URL: str = "http://localhost:3000"
    API_SECRET: str = "change-me-now"
    
    # Drain settings
    DRAIN_BATCH_SIZE: int = 100
    DRAIN_INTERVAL_SECONDS: int = 5
    
    # Security
    ALLOWED_HOSTS: List[str] = ["*"]
    CORS_ORIGINS: List[str] = ["http://localhost:3000"]
    
    class Config:
        env_file = ".env"
        case_sensitive = True


settings = Settings()

