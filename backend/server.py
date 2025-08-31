from fastapi import FastAPI, APIRouter, HTTPException, Cookie, Depends, Header
from dotenv import load_dotenv
from starlette.middleware.cors import CORSMiddleware
from motor.motor_asyncio import AsyncIOMotorClient
import os
import logging
from pathlib import Path
from pydantic import BaseModel, Field
from typing import List, Optional, Dict, Any
import uuid
from datetime import datetime, timezone
import base64
import asyncio
import httpx
from fastapi.responses import JSONResponse

ROOT_DIR = Path(__file__).parent
load_dotenv(ROOT_DIR / '.env')

# MongoDB connection
mongo_url = os.environ['MONGO_URL']
client = AsyncIOMotorClient(mongo_url)
db = client[os.environ['DB_NAME']]

# Create the main app
app = FastAPI()

# Create a router with the /api prefix
api_router = APIRouter(prefix="/api")

# AI Integration imports
try:
    from emergentintegrations.llm.chat import LlmChat, UserMessage
    from emergentintegrations.llm.openai.image_generation import OpenAIImageGeneration
    AI_AVAILABLE = True
except ImportError:
    AI_AVAILABLE = False
    print("AI integrations not available - install emergentintegrations")

# Models
class User(BaseModel):
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    email: str
    name: str
    picture: Optional[str] = None
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class ContentRequest(BaseModel):
    content_type: str  # "social_post", "flyer", "radio_script", "marketing_plan"
    business_name: str
    business_type: str
    target_audience: str
    key_message: str
    tone: str = "professional"  # professional, casual, exciting, friendly
    additional_details: Optional[str] = None

class GeneratedContent(BaseModel):
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    user_id: str
    content_type: str
    business_name: str
    text_content: str
    image_base64: Optional[str] = None
    prompt_used: str
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class Session(BaseModel):
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    user_id: str
    session_token: str
    expires_at: datetime
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

# Authentication dependency
async def get_current_user(session_token: Optional[str] = Cookie(None), authorization: Optional[str] = Header(None)) -> Optional[str]:
    """Get current user from session token in cookie or authorization header"""
    token = session_token
    if not token and authorization and authorization.startswith("Bearer "):
        token = authorization.split(" ")[1]
    
    if not token:
        return None
    
    # Check if session exists and is valid
    session = await db.sessions.find_one({
        "session_token": token,
        "expires_at": {"$gt": datetime.now(timezone.utc)}
    })
    
    if session:
        return session["user_id"]
    return None

# AI Content Generation Functions
async def generate_text_content(content_type: str, business_name: str, business_type: str, 
                               target_audience: str, key_message: str, tone: str, 
                               additional_details: str = None) -> tuple[str, str]:
    """Generate text content using AI"""
    if not AI_AVAILABLE:
        return "AI not available", "sample prompt"
    
    try:
        api_key = os.environ.get('EMERGENT_LLM_KEY')
        if not api_key:
            raise HTTPException(status_code=500, detail="AI API key not configured")
        
        # Create specialized prompts for each content type
        prompts = {
            "social_post": f"""Create an engaging social media post for {business_name}, a {business_type} business.
Target audience: {target_audience}
Key message: {key_message}
Tone: {tone}
Additional details: {additional_details or 'None'}

Requirements:
- Maximum 280 characters for Twitter or 125 words for Facebook/LinkedIn
- Include relevant hashtags (3-5)
- Call-to-action
- Engaging and shareable content
- Appropriate emojis if tone allows

Format the response as a ready-to-post social media update.""",

            "flyer": f"""Create compelling flyer content for {business_name}, a {business_type} business.
Target audience: {target_audience}
Key message: {key_message}
Tone: {tone}
Additional details: {additional_details or 'None'}

Requirements:
- Eye-catching headline
- Clear value proposition
- Contact information placeholder
- Call-to-action
- Benefits or features (3-5 bullet points)
- Event details if applicable

Format as structured flyer text with clear sections for headline, body, and contact info.""",

            "radio_script": f"""Write a radio advertisement script for {business_name}, a {business_type} business.
Target audience: {target_audience}
Key message: {key_message}
Tone: {tone}
Additional details: {additional_details or 'None'}

Requirements:
- 30-second format (approximately 75 words)
- Attention-grabbing opening
- Clear message delivery
- Strong call-to-action
- Easy to pronounce and remember
- Include timing cues in brackets

Format as a professional radio script with speaker directions.""",

            "marketing_plan": f"""Create a comprehensive marketing plan for {business_name}, a {business_type} business.
Target audience: {target_audience}
Key message: {key_message}
Tone: {tone}
Additional details: {additional_details or 'None'}

Requirements:
- Executive Summary
- Target Market Analysis
- Marketing Objectives (3-5)
- Marketing Strategies and Tactics
- Budget Considerations
- Timeline (3-6 months)
- Success Metrics
- Next Steps

Format as a structured business document with clear sections and actionable recommendations."""
        }
        
        system_message = f"You are an expert marketing copywriter specializing in content for small and medium enterprises (SMEs). Create professional, engaging, and effective marketing content that drives results for local businesses."
        
        chat = LlmChat(
            api_key=api_key,
            session_id=str(uuid.uuid4()),
            system_message=system_message
        ).with_model("openai", "gpt-4o-mini")
        
        user_message = UserMessage(text=prompts.get(content_type, prompts["social_post"]))
        response = await chat.send_message(user_message)
        
        return response, prompts.get(content_type, prompts["social_post"])
    
    except Exception as e:
        logging.error(f"Error generating text content: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Failed to generate content: {str(e)}")

async def generate_image_content(business_name: str, business_type: str, content_description: str) -> Optional[str]:
    """Generate image content using AI"""
    if not AI_AVAILABLE:
        return None
    
    try:
        api_key = os.environ.get('EMERGENT_LLM_KEY')
        if not api_key:
            return None
        
        image_gen = OpenAIImageGeneration(api_key=api_key)
        
        # Create a detailed prompt for business imagery
        image_prompt = f"""Professional marketing image for {business_name}, a {business_type} business. 
{content_description}
Style: Modern, clean, professional marketing material
Quality: High-resolution, suitable for digital marketing
Colors: Vibrant but professional, good contrast
Layout: Suitable for flyers or social media posts
Text space: Leave room for text overlay
Brand-appropriate imagery that appeals to the target demographic"""
        
        images = await image_gen.generate_images(
            prompt=image_prompt,
            model="gpt-image-1",
            number_of_images=1
        )
        
        if images and len(images) > 0:
            image_base64 = base64.b64encode(images[0]).decode('utf-8')
            return image_base64
        return None
    
    except Exception as e:
        logging.error(f"Error generating image: {str(e)}")
        return None

# API Routes
@api_router.get("/")
async def root():
    return {"message": "AI Content & Marketing Studio API"}

@api_router.get("/auth/profile")
async def get_profile(user_id: str = Depends(get_current_user)):
    """Get user profile"""
    if not user_id:
        raise HTTPException(status_code=401, detail="Not authenticated")
    
    user = await db.users.find_one({"id": user_id})
    if not user:
        raise HTTPException(status_code=404, detail="User not found")
    
    return User(**user)

@api_router.post("/auth/session")
async def create_session(session_id: str = Header(..., alias="X-Session-ID")):
    """Create session from Emergent auth"""
    try:
        # Call Emergent auth API
        async with httpx.AsyncClient() as client:
            response = await client.get(
                "https://demobackend.emergentagent.com/auth/v1/env/oauth/session-data",
                headers={"X-Session-ID": session_id}
            )
            
            if response.status_code != 200:
                raise HTTPException(status_code=401, detail="Invalid session")
            
            user_data = response.json()
        
        # Create or get user
        existing_user = await db.users.find_one({"email": user_data["email"]})
        if not existing_user:
            user = User(
                email=user_data["email"],
                name=user_data["name"],
                picture=user_data.get("picture")
            )
            await db.users.insert_one(user.dict())
            user_id = user.id
        else:
            user_id = existing_user["id"]
        
        # Create session
        session = Session(
            user_id=user_id,
            session_token=user_data["session_token"],
            expires_at=datetime.now(timezone.utc).replace(day=datetime.now(timezone.utc).day + 7)
        )
        await db.sessions.insert_one(session.dict())
        
        # Set cookie and return user data
        response = JSONResponse({
            "user": {
                "id": user_id,
                "email": user_data["email"],
                "name": user_data["name"],
                "picture": user_data.get("picture")
            }
        })
        
        response.set_cookie(
            key="session_token",
            value=user_data["session_token"],
            httponly=True,
            secure=True,
            samesite="none",
            path="/",
            max_age=7 * 24 * 60 * 60  # 7 days
        )
        
        return response
    
    except Exception as e:
        logging.error(f"Error creating session: {str(e)}")
        raise HTTPException(status_code=500, detail="Failed to create session")

@api_router.post("/content/generate", response_model=GeneratedContent)
async def generate_content(request: ContentRequest, user_id: str = Depends(get_current_user)):
    """Generate marketing content"""
    if not user_id:
        raise HTTPException(status_code=401, detail="Authentication required")
    
    try:
        # Generate text content
        text_content, prompt_used = await generate_text_content(
            request.content_type,
            request.business_name,
            request.business_type,
            request.target_audience,
            request.key_message,
            request.tone,
            request.additional_details
        )
        
        # Generate image for flyers
        image_base64 = None
        if request.content_type == "flyer":
            image_description = f"Create a professional flyer background for {request.business_name}, {request.business_type}. Target audience: {request.target_audience}. Message: {request.key_message}"
            image_base64 = await generate_image_content(
                request.business_name,
                request.business_type,
                image_description
            )
        
        # Save to database
        content = GeneratedContent(
            user_id=user_id,
            content_type=request.content_type,
            business_name=request.business_name,
            text_content=text_content,
            image_base64=image_base64,
            prompt_used=prompt_used
        )
        
        await db.generated_content.insert_one(content.dict())
        return content
    
    except Exception as e:
        logging.error(f"Error generating content: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Failed to generate content: {str(e)}")

@api_router.get("/content/history", response_model=List[GeneratedContent])
async def get_content_history(user_id: str = Depends(get_current_user)):
    """Get user's content generation history"""
    if not user_id:
        raise HTTPException(status_code=401, detail="Authentication required")
    
    content_list = await db.generated_content.find(
        {"user_id": user_id}
    ).sort("created_at", -1).to_list(50)
    
    return [GeneratedContent(**content) for content in content_list]

@api_router.delete("/content/{content_id}")
async def delete_content(content_id: str, user_id: str = Depends(get_current_user)):
    """Delete generated content"""
    if not user_id:
        raise HTTPException(status_code=401, detail="Authentication required")
    
    result = await db.generated_content.delete_one({
        "id": content_id,
        "user_id": user_id
    })
    
    if result.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Content not found")
    
    return {"message": "Content deleted successfully"}

# Include the router in the main app
app.include_router(api_router)

app.add_middleware(
    CORSMiddleware,
    allow_credentials=True,
    allow_origins=os.environ.get('CORS_ORIGINS', '*').split(','),
    allow_methods=["*"],
    allow_headers=["*"],
)

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

@app.on_event("shutdown")
async def shutdown_db_client():
    client.close()