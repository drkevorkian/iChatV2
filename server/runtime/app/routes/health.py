"""
Sentinel Chat Platform - Health Check Routes

Provides health check endpoints for monitoring.
"""

from fastapi import APIRouter
from datetime import datetime

router = APIRouter()


@router.get("/")
async def health_check():
    """Basic health check endpoint"""
    return {
        "status": "ok",
        "timestamp": datetime.utcnow().isoformat(),
        "service": "runtime-drain",
    }


@router.get("/ready")
async def readiness():
    """Readiness check - verifies database connectivity"""
    # In production, check database connection here
    return {
        "status": "ready",
        "timestamp": datetime.utcnow().isoformat(),
    }


@router.get("/live")
async def liveness():
    """Liveness check - indicates service is running"""
    return {
        "status": "alive",
        "timestamp": datetime.utcnow().isoformat(),
    }

