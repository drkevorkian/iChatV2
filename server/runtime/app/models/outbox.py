"""
Sentinel Chat Platform - Temporary Outbox Model

SQLAlchemy model for the temp_outbox table.
Represents messages queued for delivery to the primary server.
"""

from sqlalchemy import Column, BigInteger, String, Text, Integer, DateTime, Index
from sqlalchemy.sql import func
from app.database import Base


class TempOutbox(Base):
    """
    Temporary outbox table model.
    
    Stores encrypted messages that are queued when the primary server
    is unavailable. The drain service processes these messages.
    """
    __tablename__ = "temp_outbox"
    
    id = Column(BigInteger, primary_key=True, autoincrement=True)
    room_id = Column(String(255), nullable=False, index=True)
    sender_handle = Column(String(100), nullable=False)
    cipher_blob = Column(Text, nullable=False)
    filter_version = Column(Integer, nullable=False, default=1)
    queued_at = Column(DateTime, nullable=False, server_default=func.now(), index=True)
    delivered_at = Column(DateTime, nullable=True, index=True)
    deleted_at = Column(DateTime, nullable=True, index=True)
    
    # Composite indexes for common queries
    __table_args__ = (
        Index('idx_outbox_pending', 'room_id', 'delivered_at', 'deleted_at'),
    )

