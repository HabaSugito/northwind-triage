// React entry point — mounts the App component into the #app div
// rendered by resources/views/app.blade.php.
import '../css/app.css';
import { createRoot } from 'react-dom/client';
import App from './components/App';

createRoot(document.getElementById('app')).render(<App />);
