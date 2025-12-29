"""
Sentinel Chat Platform - Python Runtime Service Main Entry Point

FastAPI application that drains the temporary MySQL outbox database
and syncs messages to the primary NestJS server when available.

Security: All database queries use SQLAlchemy ORM with parameterized queries.
"""

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.middleware.trustedhost import TrustedHostMiddleware
import uvicorn
from app.config import settings
from app.database import engine, SessionLocal
from app.routes import drain, health

# Create database tables
from app.models import Base
Base.metadata.create_all(bind=engine)

# Initialize FastAPI app
app = FastAPI(
    title="Sentinel Chat Runtime Service",
    description="Message draining service for temporary outbox",
    version="1.0.0",
)

# Security middleware
app.add_middleware(
    TrustedHostMiddleware,
    allowed_hosts=settings.ALLOWED_HOSTS,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.CORS_ORIGINS,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Include routers
app.include_router(health.router, prefix="/health", tags=["health"])
app.include_router(drain.router, prefix="/drain", tags=["drain"])


@app.on_event("startup")
async def startup_event():
    """Initialize services on startup"""
    print("Sentinel Chat Runtime Service starting...")


@app.on_event("shutdown")
async def shutdown_event():
    """Cleanup on shutdown"""
    print("Sentinel Chat Runtime Service shutting down...")


if __name__ == "__main__":
    uvicorn.run(
        "app.main:app",
        host=settings.HOST,
        port=settings.PORT,
        reload=settings.DEBUG,
    )

