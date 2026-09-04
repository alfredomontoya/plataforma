import axios from 'axios';

const api = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } });

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('accessToken');
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
});

api.interceptors.response.use(
    (res) => res,
    (err) => {
        if (err.response?.status === 401 && !location.pathname.startsWith('/login')) {
            localStorage.removeItem('accessToken');
            window.location.href = '/login';
        }
        return Promise.reject(err);
    },
);

export const toLocalDay = (d) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

export const todayLocal = () => toLocalDay(new Date());

export default api;
