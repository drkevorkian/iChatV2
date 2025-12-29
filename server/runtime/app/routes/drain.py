"""
Sentinel Chat Platform - Message Draining Routes

Handles draining messages from temporary outbox to primary server.
"""

from fastapi import APIRouter, HTTPException, Depends
from sqlalchemy.orm import Session
from app.database import SessionLocal
from app.models.outbox import TempOutbox
from app.services.drain_service import DrainService
from app.config import settings

router = APIRouter()


def get_db():
    """Dependency for database session"""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


@router.post("/run")
async def drain_messages(db: Session = Depends(get_db)):
    """
    Manually trigger message draining.
    
    Retrieves pending messages from temp_outbox and attempts to
    deliver them to the primary NestJS server.
    """
    try:
        drain_service = DrainService(db)
        result = drain_service.drain_batch(settings.DRAIN_BATCH_SIZE)
        return {
            "success": True,
            "processed": result["processed"],
            "delivered": result["delivered"],
            "failed": result["failed"],
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@router.get("/status")
async def drain_status(db: Session = Depends(get_db)):
    """Get current drain status and queue depth"""
    try:
        pending_count = (
            db.query(TempOutbox)
            .filter(
                TempOutbox.delivered_at.is_(None),
                TempOutbox.deleted_at.is_(None),
            )
            .count()
        )
        
        return {
            "pending_messages": pending_count,
            "batch_size": settings.DRAIN_BATCH_SIZE,
            "interval_seconds": settings.DRAIN_INTERVAL_SECONDS,
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

