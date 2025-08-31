import React, { useState, useEffect, useContext, createContext } from "react";
import "./App.css";
import { BrowserRouter, Routes, Route, Navigate, useNavigate, useLocation } from "react-router-dom";
import axios from "axios";

const BACKEND_URL = process.env.REACT_APP_BACKEND_URL;
const API = `${BACKEND_URL}/api`;

// Auth Context
const AuthContext = createContext();

const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const login = async (sessionId) => {
    try {
      const response = await axios.post(`${API}/auth/session`, {}, {
        headers: { 'X-Session-ID': sessionId },
        withCredentials: true
      });
      setUser(response.data.user);
      return true;
    } catch (error) {
      console.error('Login failed:', error);
      return false;
    }
  };

  const logout = () => {
    setUser(null);
    // Clear cookie by setting it to expire
    document.cookie = "session_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
  };

  const checkAuth = async () => {
    try {
      const response = await axios.get(`${API}/auth/profile`, {
        withCredentials: true
      });
      setUser(response.data);
    } catch (error) {
      setUser(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    checkAuth();
  }, []);

  return (
    <AuthContext.Provider value={{ user, login, logout, loading }}>
      {children}
    </AuthContext.Provider>
  );
};

const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

// Components
const Header = () => {
  const { user, logout } = useAuth();

  return (
    <header className="bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-lg">
      <div className="container mx-auto px-4 py-4 flex justify-between items-center">
        <div className="flex items-center space-x-3">
          <div className="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
            <span className="text-xl font-bold">AI</span>
          </div>
          <h1 className="text-2xl font-bold">Content Studio</h1>
        </div>
        {user && (
          <div className="flex items-center space-x-4">
            <div className="flex items-center space-x-2">
              {user.picture && (
                <img src={user.picture} alt={user.name} className="w-8 h-8 rounded-full" />
              )}
              <span className="hidden md:block">{user.name}</span>
            </div>
            <button
              onClick={logout}
              className="bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition-colors"
            >
              Logout
            </button>
          </div>
        )}
      </div>
    </header>
  );
};

const Login = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { login } = useAuth();

  useEffect(() => {
    // Check for session ID in URL fragment
    const hash = location.hash;
    if (hash && hash.includes('session_id=')) {
      const sessionId = hash.split('session_id=')[1].split('&')[0];
      handleLogin(sessionId);
    }
  }, [location]);

  const handleLogin = async (sessionId) => {
    const success = await login(sessionId);
    if (success) {
      navigate('/dashboard');
    } else {
      alert('Login failed. Please try again.');
    }
  };

  const redirectToAuth = () => {
    const redirectUrl = encodeURIComponent(window.location.origin + '/profile');
    window.location.href = `https://auth.emergentagent.com/?redirect=${redirectUrl}`;
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-purple-50 to-blue-50 flex items-center justify-center">
      <div className="bg-white rounded-2xl shadow-xl p-8 max-w-md w-full mx-4">
        <div className="text-center mb-8">
          <div className="w-16 h-16 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <span className="text-2xl font-bold text-white">AI</span>
          </div>
          <h1 className="text-3xl font-bold text-gray-800 mb-2">Content Studio</h1>
          <p className="text-gray-600">AI-powered marketing content for your business</p>
        </div>
        
        <div className="space-y-4 mb-8">
          <div className="text-center">
            <h2 className="text-xl font-semibold text-gray-800 mb-4">Features</h2>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div className="bg-purple-50 p-3 rounded-lg">
                <div className="font-medium text-purple-800">Social Posts</div>
                <div className="text-purple-600">Engaging content</div>
              </div>
              <div className="bg-blue-50 p-3 rounded-lg">
                <div className="font-medium text-blue-800">Flyers</div>
                <div className="text-blue-600">Visual marketing</div>
              </div>
              <div className="bg-green-50 p-3 rounded-lg">
                <div className="font-medium text-green-800">Radio Scripts</div>
                <div className="text-green-600">Audio ads</div>
              </div>
              <div className="bg-orange-50 p-3 rounded-lg">
                <div className="font-medium text-orange-800">Marketing Plans</div>
                <div className="text-orange-600">Strategic planning</div>
              </div>
            </div>
          </div>
        </div>

        <button
          onClick={redirectToAuth}
          className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200 transform hover:scale-105"
        >
          Get Started with Google
        </button>
      </div>
    </div>
  );
};

const ContentForm = ({ onGenerate, loading }) => {
  const [formData, setFormData] = useState({
    content_type: 'social_post',
    business_name: '',
    business_type: '',
    target_audience: '',
    key_message: '',
    tone: 'professional',
    additional_details: ''
  });

  const handleSubmit = (e) => {
    e.preventDefault();
    onGenerate(formData);
  };

  const handleChange = (e) => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value
    });
  };

  return (
    <form onSubmit={handleSubmit} className="bg-white rounded-xl shadow-lg p-6 space-y-6">
      <h2 className="text-2xl font-bold text-gray-800 mb-4">Generate Content</h2>
      
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">Content Type</label>
          <select
            name="content_type"
            value={formData.content_type}
            onChange={handleChange}
            className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
            required
          >
            <option value="social_post">Social Media Post</option>
            <option value="flyer">Flyer</option>
            <option value="radio_script">Radio Script</option>
            <option value="marketing_plan">Marketing Plan</option>
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">Business Name</label>
          <input
            type="text"
            name="business_name"
            value={formData.business_name}
            onChange={handleChange}
            className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
            placeholder="e.g., Mike's Coffee Shop"
            required
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">Business Type</label>
          <input
            type="text"
            name="business_type"
            value={formData.business_type}
            onChange={handleChange}
            className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
            placeholder="e.g., Restaurant, Hair Salon, Auto Repair"
            required
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">Tone</label>
          <select
            name="tone"
            value={formData.tone}
            onChange={handleChange}
            className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
          >
            <option value="professional">Professional</option>
            <option value="casual">Casual</option>
            <option value="exciting">Exciting</option>
            <option value="friendly">Friendly</option>
          </select>
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Target Audience</label>
        <input
          type="text"
          name="target_audience"
          value={formData.target_audience}
          onChange={handleChange}
          className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
          placeholder="e.g., Local families, Young professionals, Seniors"
          required
        />
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Key Message</label>
        <textarea
          name="key_message"
          value={formData.key_message}
          onChange={handleChange}
          rows="3"
          className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
          placeholder="What's the main message you want to communicate?"
          required
        />
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-2">Additional Details (Optional)</label>
        <textarea
          name="additional_details"
          value={formData.additional_details}
          onChange={handleChange}
          rows="2"
          className="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
          placeholder="Any special offers, events, or specific requirements?"
        />
      </div>

      <button
        type="submit"
        disabled={loading}
        className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 disabled:opacity-50 text-white font-semibold py-3 px-6 rounded-lg transition-all duration-200"
      >
        {loading ? 'Generating...' : 'Generate Content'}
      </button>
    </form>
  );
};

const ContentDisplay = ({ content }) => {
  if (!content) return null;

  const copyToClipboard = (text) => {
    navigator.clipboard.writeText(text);
    alert('Content copied to clipboard!');
  };

  return (
    <div className="bg-white rounded-xl shadow-lg p-6">
      <div className="flex justify-between items-center mb-4">
        <h3 className="text-xl font-bold text-gray-800 capitalize">
          {content.content_type.replace('_', ' ')} for {content.business_name}
        </h3>
        <button
          onClick={() => copyToClipboard(content.text_content)}
          className="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg transition-colors"
        >
          Copy Text
        </button>
      </div>
      
      {content.image_base64 && (
        <div className="mb-6">
          <img
            src={`data:image/png;base64,${content.image_base64}`}
            alt="Generated flyer"
            className="w-full max-w-md mx-auto rounded-lg shadow-md"
          />
        </div>
      )}
      
      <div className="bg-gray-50 p-4 rounded-lg">
        <pre className="whitespace-pre-wrap text-gray-800 font-medium">
          {content.text_content}
        </pre>
      </div>
      
      <div className="mt-4 text-sm text-gray-500">
        Generated on {new Date(content.created_at).toLocaleDateString()}
      </div>
    </div>
  );
};

const Dashboard = () => {
  const [currentContent, setCurrentContent] = useState(null);
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(false);
  const [activeTab, setActiveTab] = useState('generate');

  const generateContent = async (formData) => {
    setLoading(true);
    try {
      const response = await axios.post(`${API}/content/generate`, formData, {
        withCredentials: true
      });
      setCurrentContent(response.data);
      loadHistory(); // Refresh history
    } catch (error) {
      console.error('Error generating content:', error);
      alert('Failed to generate content. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const loadHistory = async () => {
    try {
      const response = await axios.get(`${API}/content/history`, {
        withCredentials: true
      });
      setHistory(response.data);
    } catch (error) {
      console.error('Error loading history:', error);
    }
  };

  const deleteContent = async (contentId) => {
    if (!confirm('Are you sure you want to delete this content?')) return;
    
    try {
      await axios.delete(`${API}/content/${contentId}`, {
        withCredentials: true
      });
      loadHistory(); // Refresh history
      if (currentContent && currentContent.id === contentId) {
        setCurrentContent(null);
      }
    } catch (error) {
      console.error('Error deleting content:', error);
      alert('Failed to delete content.');
    }
  };

  useEffect(() => {
    loadHistory();
  }, []);

  return (
    <div className="min-h-screen bg-gray-50">
      <Header />
      
      <div className="container mx-auto px-4 py-8">
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-gray-800 mb-2">AI Content & Marketing Studio</h1>
          <p className="text-gray-600">Generate professional marketing content for your business</p>
        </div>

        <div className="flex flex-wrap gap-4 mb-8">
          <button
            onClick={() => setActiveTab('generate')}
            className={`px-6 py-3 rounded-lg font-medium transition-colors ${
              activeTab === 'generate'
                ? 'bg-purple-600 text-white'
                : 'bg-white text-gray-700 hover:bg-gray-50'
            }`}
          >
            Generate Content
          </button>
          <button
            onClick={() => setActiveTab('history')}
            className={`px-6 py-3 rounded-lg font-medium transition-colors ${
              activeTab === 'history'
                ? 'bg-purple-600 text-white'
                : 'bg-white text-gray-700 hover:bg-gray-50'
            }`}
          >
            Content History ({history.length})
          </button>
        </div>

        {activeTab === 'generate' && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <ContentForm onGenerate={generateContent} loading={loading} />
            {currentContent && <ContentDisplay content={currentContent} />}
          </div>
        )}

        {activeTab === 'history' && (
          <div className="space-y-6">
            {history.length === 0 ? (
              <div className="text-center py-12">
                <div className="text-gray-400 text-lg">No content generated yet</div>
                <button
                  onClick={() => setActiveTab('generate')}
                  className="mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg"
                >
                  Create Your First Content
                </button>
              </div>
            ) : (
              history.map((content) => (
                <div key={content.id} className="relative">
                  <button
                    onClick={() => deleteContent(content.id)}
                    className="absolute top-4 right-4 z-10 bg-red-100 hover:bg-red-200 text-red-600 p-2 rounded-lg transition-colors"
                  >
                    Delete
                  </button>
                  <ContentDisplay content={content} />
                </div>
              ))
            )}
          </div>
        )}
      </div>
    </div>
  );
};

const Profile = () => {
  return <Navigate to="/dashboard" replace />;
};

const ProtectedRoute = ({ children }) => {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600 mx-auto mb-4"></div>
          <div className="text-gray-600">Loading...</div>
        </div>
      </div>
    );
  }

  return user ? children : <Navigate to="/login" replace />;
};

function App() {
  return (
    <div className="App">
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/profile" element={<Profile />} />
            <Route
              path="/dashboard"
              element={
                <ProtectedRoute>
                  <Dashboard />
                </ProtectedRoute>
              }
            />
            <Route path="/" element={<Navigate to="/dashboard" replace />} />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </div>
  );
}

export default App;